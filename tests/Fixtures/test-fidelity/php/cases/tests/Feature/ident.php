<?php
use App\Calc;
uses(Tests\TestCase::class);
beforeEach(function () { $this->spy = Mockery::spy(Calc::class); });
it('spies', function () { $this->spy->shouldHaveReceived('total'); });
it('mock only', function () { expect($this->spy->total())->toBe($this->spy->total()); });
