<?php
use App\Sut;
use App\Repo;

beforeEach(function () {
    $this->value = 1;
    $this->repo = Mockery::mock(Repo::class);
    $this->repo->shouldReceive('total')->andReturn(10);
    $this->list = [1];
});

it('overrides the value', function () {
    $this->value = 2;
    expect($this->value)->toBe(2);
});

it('overrides the pin', function () {
    $this->repo->shouldReceive('total')->andReturn(20);
    expect($this->repo->total())->toBe(10);
});

it('appends in a callback', function () {
    (new Sut())->on(function () { $this->list[] = 2; });
    (new Sut())->fire();
    expect($this->list)->toBe([1, 2]);
});

it('passes the list by ref', function () {
    $sut = new Sut();
    $sut->fill($this->list);
    expect($this->list)->toBe([1, 2]);
});

it('foreach by ref', function () {
    $items = [1];
    foreach ($items as &$item) { $item = 5; }
    expect($items)->toBe([5]);
});

it('reads a dataset', function (int $n) {
    expect($n)->toBe(1);
})->with([1, 2]);

it('reads in an arrow', fn () => expect($this->value)->toBe(1));

describe('nested', function () {
    beforeEach(function () {
        $this->repo->shouldReceive('find')->andReturn('d');
    });
    it('reads', function () {
        expect($this->repo->find(1))->toBe('d');
    });
});

it('outside the describe', function () {
    $this->repo->shouldReceive('find')->andReturn('e')->byDefault();
    expect($this->repo->find(1))->toBe('d');
});
