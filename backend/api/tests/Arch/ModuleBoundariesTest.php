<?php

declare(strict_types=1);

use App\Shared\Providers\ModuleServiceProvider;

/*
| Module boundaries (docs/01 section 3, R1-S2): a module may use another
| module only through its public App\Modules\<Other>\Contracts namespace
| (interfaces, DTOs, events) — never its Models, Database, Actions, Http,
| Policies or Domain. Shared code never depends on any module.
*/

const MODULES = ['Users', 'Products', 'Orders', 'Notifications'];

foreach (MODULES as $module) {
    foreach (array_diff(MODULES, [$module]) as $other) {
        arch($module.' uses '.$other.' only through its Contracts', function () use ($module, $other): void {
            expect('App\\Modules\\'.$module)
                ->not()->toUse('App\\Modules\\'.$other)
                ->ignoring('App\\Modules\\'.$other.'\\Contracts');
        });
    }
}

arch('Shared does not depend on any module', function (): void {
    expect('App\\Shared')->not()->toUse('App\\Modules');
});

arch('every module has a final service provider extending ModuleServiceProvider', function (): void {
    foreach (MODULES as $module) {
        expect('App\\Modules\\'.$module.'\\Providers\\'.$module.'ServiceProvider')
            ->toExtend(ModuleServiceProvider::class)
            ->toBeFinal();
    }
});
