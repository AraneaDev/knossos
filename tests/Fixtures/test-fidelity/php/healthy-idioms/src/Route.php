<?php

namespace App;

enum Leg: string
{
    case Air = 'air';
    case Sea = 'sea';
}

enum Suit
{
    case Hearts;
    case Spades;
}

interface Router
{
    public function reroute(Leg $to): bool;

    public function deal(Suit $suit): bool;
}

final class Dispatch
{
    public function __construct(private Router $router) {}

    public function send(Leg $to): bool
    {
        return $this->router->reroute($to);
    }

    public function play(Suit $suit): bool
    {
        return $this->router->deal($suit);
    }
}
