<?php

namespace Livewire {
    if (! class_exists(Component::class)) {
        abstract class Component {}
    }
}

namespace Lantern\Tests\Feature\Filament\Fixtures {
    trait HandlesPage
    {
        public function __invoke() { return 'ok'; }
    }

    final class PageComponent extends \Livewire\Component
    {
        use HandlesPage;
    }
}
