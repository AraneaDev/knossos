<?php

namespace App;

/**
 * @method int magicCount()
 * @method static self make()
 */
abstract class Model
{
    public function save(): bool
    {
        return true;
    }
}

trait Magic
{
    public function __call($name, $args)
    {
        return null;
    }
}

class UsesMagic
{
    use Magic;
}

class MagicParent
{
    public function __call($name, $args)
    {
        return null;
    }
}

class MagicChild extends MagicParent
{
}

class Post extends Model
{
    public function title(): string
    {
        return '';
    }
}

class Logger
{
    public function log()
    {
        $args = func_get_args();
    }

    public function write($message)
    {
        $extra = func_get_args();
    }

    public function count()
    {
        return func_num_args();
    }

    public function first()
    {
        return func_get_arg(0);
    }

    public function nested($message)
    {
        return function () {
            return func_get_args();
        };
    }
}

interface Second
{
    public function second(): int;
}

class Primary
{
    public function first(): int
    {
        return 1;
    }

    private function locked(): int
    {
        return 0;
    }
}
