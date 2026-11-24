<?php

use Inertia\Testing\AssertableInertia as Assert;

it('shows every queue on the door board', function () {
    $this->get('/board')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('board/Index')
        ->has('snapshot.queues', 4)
        ->where('takeUrl', url('/take')));
});

it('shows the public entry page', function () {
    $this->get('/')->assertOk();
});
