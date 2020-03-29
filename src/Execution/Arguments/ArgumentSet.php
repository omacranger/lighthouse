<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Nuwave\Lighthouse\Schema\Directives\RenameDirective;
use Nuwave\Lighthouse\Schema\Directives\SpreadDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgBuilderDirective;
use Nuwave\Lighthouse\Support\Utils;

class ArgumentSet
{
    /**
     * An associative array from argument names to arguments.
     *
     * @var \Nuwave\Lighthouse\Execution\Arguments\Argument[]
     */
    public $arguments = [];

    /**
     * A list of directives.
     *
     * This may be coming from the field the arguments are a part of
     * or the parent argument when in a tree of nested inputs.
     *
     * @var \Illuminate\Support\Collection<\Nuwave\Lighthouse\Support\Contracts\Directive>
     */
    public $directives;

    /**
     * Get a plain array representation of this ArgumentSet.
     *
     * @return array
     */
    public function toArray(): array
    {
        $plainArguments = [];

        foreach ($this->arguments as $name => $argument) {
            $plainArguments[$name] = $argument->toPlain();
        }

        return $plainArguments;
    }

    /**
     * Check if the ArgumentSet has a non-null value with the given key.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        /** @var \Nuwave\Lighthouse\Execution\Arguments\Argument|null $argument */
        $argument = $this->arguments[$key] ?? null;

        if ($argument === null) {
            return false;
        }

        return $argument->value !== null;
    }

    /**
     * Apply the @spread directive and return a new, modified instance.
     *
     * @return self
     */
    public function spread(): self
    {
        $argumentSet = new self();
        $argumentSet->directives = $this->directives;

        foreach ($this->arguments as $name => $argument) {
            $value = $argument->value;

            // In this case, we do not care about argument sets nested within
            // lists, spreading only makes sense for single nested inputs.
            if ($value instanceof self) {
                // Recurse down first, as that resolves the more deeply nested spreads first
                $value = $value->spread();

                if ($argument->directives->contains(
                    Utils::instanceofMatcher(SpreadDirective::class)
                )) {
                    $argumentSet->arguments += $value->arguments;
                    continue;
                }
            }

            $argumentSet->arguments[$name] = $argument;
        }

        return $argumentSet;
    }

    /**
     * Apply the @rename directive and return a new, modified instance.
     *
     * @return self
     */
    public function rename(): self
    {
        $argumentSet = new self();
        $argumentSet->directives = $this->directives;

        foreach ($this->arguments as $name => $argument) {
            // Recursively apply the renaming to nested inputs.
            // We look for further ArgumentSet instances, they
            // might be contained within an array.
            $argument->value = Utils::applyEach(
                function ($value) {
                    if ($value instanceof self) {
                        return $value->rename();
                    }

                    return $value;
                },
                $argument->value
            );

            /** @var \Nuwave\Lighthouse\Schema\Directives\RenameDirective|null $renameDirective */
            $renameDirective = $argument->directives->first(function ($directive) {
                return $directive instanceof RenameDirective;
            });

            if ($renameDirective) {
                $argumentSet->arguments[$renameDirective->attributeArgValue()] = $argument;
            } else {
                $argumentSet->arguments[$name] = $argument;
            }
        }

        return $argumentSet;
    }

    /**
     * Apply ArgBuilderDirectives and scopes to the builder.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Relations\Relation  $builder
     * @param  string[]  $scopes
     * @param  \Closure  $directiveFilter
     *
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Relations\Relation
     */
    public function enhanceBuilder($builder, array $scopes, Closure $directiveFilter = null)
    {
        self::applyArgBuilderDirectives($this, $builder, $directiveFilter);

        if($builder instanceof Builder) {
            $table = $builder->getTable();
        } elseif($builder instanceof EloquentBuilder) {
            $table = $builder->getModel()->getTable();
        } elseif($builder instanceof Model) {
            $table = $builder->getTable();
        } elseif($builder instanceof Relation) {
            $table = $builder->getParent()->getTable();
        } else {
            $table = null;
        }

        if($table) {
            // Fix a long standing issue within Eloquent https://github.com/laravel/framework/issues/4962
            $builder->select($table . '.*');
        }

        foreach ($scopes as $scope) {
            call_user_func([$builder, $scope], $this->toArray());
        }

        return $builder;
    }

    /**
     * Recursively apply the ArgBuilderDirectives onto the builder.
     *
     * TODO get rid of the reference passing in here. The issue is that @search makes a new builder instance,
     * but we must special case that in some way anyhow, as only eq filters can be added on top of search.
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Relations\Relation  $builder
     * @param  \Closure|null  $directiveFilter
     */
    protected static function applyArgBuilderDirectives(self $argumentSet, &$builder, Closure $directiveFilter = null)
    {
        foreach ($argumentSet->arguments as $argument) {
            $value = $argument->toPlain();

            // TODO switch to instanceof when we require bensampo/laravel-enum
            // Unbox Enum values to ensure their underlying value is used for queries
            if (is_a($value, '\BenSampo\Enum\Enum')) {
                $value = $value->value;
            }

            $filteredDirectives = $argument
                ->directives
                ->filter(Utils::instanceofMatcher(ArgBuilderDirective::class));

            if (! empty($directiveFilter)) {
                $filteredDirectives = $filteredDirectives->filter($directiveFilter);
            }

            $filteredDirectives->each(function (ArgBuilderDirective $argBuilderDirective) use (&$builder, $value) {
                $builder = $argBuilderDirective->handleBuilder($builder, $value);
            });

            Utils::applyEach(
                function ($value) use (&$builder, $directiveFilter) {
                    if ($value instanceof self) {
                        self::applyArgBuilderDirectives($value, $builder, $directiveFilter);
                    }
                },
                $argument->value
            );
        }
    }

    /**
     * Add a value at the dot-separated path.
     *
     * Works just like the Laravel Arr::add() function.
     * @see \Illuminate\Support\Arr
     *
     * @param  string  $path
     * @param  mixed  $value
     * @return $this
     */
    public function addValue(string $path, $value): self
    {
        $argumentSet = $this;
        $keys = explode('.', $path);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            // If the key doesn't exist at this depth, we will just create an empty ArgumentSet
            // to hold the next value, allowing us to create the ArgumentSet to hold a final
            // value at the correct depth. Then we'll keep digging into the ArgumentSet.
            if (! isset($argumentSet->arguments[$key])) {
                $argument = new Argument();
                $argument->value = new self();
                $argumentSet->arguments[$key] = $argument;
            }

            $argumentSet = $argumentSet->arguments[$key]->value;
        }

        $argument = new Argument();
        $argument->value = $value;
        $argumentSet->arguments[array_shift($keys)] = $argument;

        return $this;
    }
}
