<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PruneOutbox;
use App\Modules\Realtime\Actions\PublishOutboxBatch;
use Carbon\CarbonImmutable;
use ReflectionMethod;

it('exposes the C§5.7 realtime actions and signatures', function (): void {
    $publish = new ReflectionMethod(PublishOutboxBatch::class, 'handle');
    expect($publish->isPublic())->toBeTrue()
        ->and((string) $publish->getReturnType())->toBe('int')
        ->and((string) $publish->getParameters()[0]->getType())->toBe('int')
        ->and($publish->getParameters()[0]->getName())->toBe('limit')
        ->and($publish->getParameters()[0]->getDefaultValue())->toBe(100);

    $prune = new ReflectionMethod(PruneOutbox::class, 'handle');
    expect($prune->isPublic())->toBeTrue()
        ->and((string) $prune->getReturnType())->toBe('int')
        ->and((string) $prune->getParameters()[0]->getType())->toBe(CarbonImmutable::class)
        ->and($prune->getParameters()[0]->getName())->toBe('now');
});
