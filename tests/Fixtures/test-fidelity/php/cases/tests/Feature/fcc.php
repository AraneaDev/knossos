<?php
beforeEach(function () { $this->count = 0; });
$hook = beforeEach(...);
function bumpIt(): void { $t = test(...); $t()->count = 5; }
it('uses a first-class test()', function () {
    bumpIt();
    $counter = $this->count(...);
    expect($this->count)->toBe(5);
});
