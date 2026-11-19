<?php

use App\Models\Medicine;
use App\Models\Scale;
use App\Models\User;
use App\Services\CalibrationService;
use App\Services\CountingSessionService;
use App\Services\DeviceReadingService;
use App\Services\InventoryCountService;
use App\Services\SimulatorService;
use App\Services\UserManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function inventoryMedicine(array $overrides = []): Medicine
{
    return Medicine::create(array_merge(['code' => (string) Str::uuid(), 'generic_name' => 'Paracetamol', 'strength' => '500 mg', 'dosage_form' => 'Tablet', 'manufacturer' => 'Demo', 'low_stock_threshold' => 10], $overrides));
}
function inventoryScenario(): array
{
    config(['inventory.simulation' => true]);
    $user = User::factory()->create();
    $medicine = inventoryMedicine(['simulation' => true]);
    $sim = app(SimulatorService::class);
    $scale = $sim->scale();
    $s = app(CountingSessionService::class)->acquire($user, $medicine, $scale);
    foreach (['reboot', 'tare', 'sample_one', 'sample_ten', 'calibrate'] as $action) {
        $sim->send($action);
    }
    $profile = app(CalibrationService::class)->save($user, $s->id);
    $command = app(CalibrationService::class)->apply($user, $s->id, $profile->id);
    $sim->send('ack');
    $sim->send('tare');
    $r = $sim->send('stable', 100);

    return compact('user', 'medicine', 'sim', 'scale', 's', 'profile', 'command', 'r');
}
function inventoryInput(array $f, array $overrides = []): array
{
    return array_merge(['medicine_id' => $f['medicine']->id, 'stock_revision' => $f['medicine']->fresh()->stock_revision, 'source' => 'scale', 'reason' => 'Complete stock check', 'identity_confirmed' => true, 'idempotency_key' => (string) Str::uuid(), 'session_id' => $f['s']->id, 'boot_id' => $f['r']['boot_id'], 'sequence' => $f['r']['sequence']], $overrides);
}
beforeEach(function () {
    Cache::flush();
});

test('admin and staff permissions are enforced through policies and livewire actions', function () {
    $staff = User::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $m = inventoryMedicine();
    expect(Gate::forUser($staff)->allows('count', $m))->toBeTrue();
    expect(Gate::forUser($staff)->allows('update', $m))->toBeFalse();
    expect(Gate::forUser($admin)->allows('update', $m))->toBeTrue();
    $this->actingAs($staff)->get('/users')->assertForbidden();
    Livewire::actingAs($staff)->test('pages::medicines')->call('edit', $m->id)->assertForbidden();
    $this->actingAs($admin)->get('/users')->assertOk();
});
test('administrator can create edit and archive a medicine without deleting history', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $component = Livewire::actingAs($admin)->test('pages::medicines')->call('edit')->set('form', ['code' => 'PAR500', 'generic_name' => 'Paracetamol', 'brand_name' => '', 'strength' => '500 mg', 'dosage_form' => 'Tablet', 'manufacturer' => 'Demo', 'notes' => '', 'low_stock_threshold' => 20, 'active' => true])->call('save')->assertHasNoErrors();
    $m = Medicine::where('code', 'PAR500')->firstOrFail();
    $component->call('edit', $m->id)->set('form.strength', '250 mg')->call('save')->assertHasNoErrors()->call('archive', $m->id);
    $this->assertDatabaseHas('medicines', ['id' => $m->id, 'strength' => '250 mg', 'active' => false]);
});
test('disabled users cannot log in or continue an existing browser session', function () {
    $user = User::factory()->create(['active' => false]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors();
    $this->assertGuest();
    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});
test('last active admin cannot be disabled or demoted', function (array $changes) {
    $admin = User::factory()->create(['role' => 'admin']);
    expect(fn () => app(UserManagementService::class)->save($admin, $admin->id, array_merge(['name' => $admin->name, 'email' => $admin->email, 'password' => '', 'role' => 'admin', 'active' => true], $changes)))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('users', ['id' => $admin->id, 'active' => true, 'role' => 'admin']);
})->with(['disable' => [['active' => false]], 'demote' => [['role' => 'staff']]]);
test('admin can manage staff accounts', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $staff = app(UserManagementService::class)->save($admin, null, ['name' => 'New Staff', 'email' => 'new@example.test', 'password' => 'A-local-password', 'role' => 'staff', 'active' => true]);
    app(UserManagementService::class)->save($admin, $staff->id, ['name' => 'New Staff', 'email' => 'new@example.test', 'password' => '', 'role' => 'staff', 'active' => false]);
    $this->assertDatabaseHas('users', ['id' => $staff->id, 'active' => false]);
});
test('device credential and identity are required', function () {
    $scale = Scale::create(['device_identifier' => 'esp32-01', 'name' => 'Scale', 'credential_hash' => Hash::make('device-secret')]);
    $this->postJson('/api/v1/device/readings', [])->assertUnauthorized();
    $this->withHeaders(['X-Device-ID' => 'esp32-01', 'Authorization' => 'Bearer wrong'])->getJson('/api/v1/device/commands')->assertUnauthorized();
    $this->withHeaders(['X-Device-ID' => 'esp32-01', 'Authorization' => 'Bearer device-secret'])->postJson('/api/v1/device/readings', ['device_id' => 'other'])->assertForbidden();
    $this->getJson('/api/v1/device/commands')->assertOk()->assertJson(['commands' => []]);
    $scale->update(['active' => false]);
    $this->getJson('/api/v1/device/commands')->assertUnauthorized();
});
test('device endpoint validates and accepts telemetry without inserting permanent readings', function () {
    $scale = Scale::create(['device_identifier' => 'esp32-01', 'name' => 'Scale', 'credential_hash' => Hash::make('secret')]);
    $headers = ['X-Device-ID' => 'esp32-01', 'Authorization' => 'Bearer secret'];
    $this->withHeaders($headers)->postJson('/api/v1/device/readings', [])->assertUnprocessable()->assertJsonValidationErrors(['boot_id', 'current_raw']);
    $r = ['device_id' => 'esp32-01', 'boot_id' => (string) Str::uuid(), 'sequence' => 0, 'calibration_session_id' => (string) Str::uuid(), 'applied_profile_id' => null, 'applied_profile_version' => null, 'tare_revision' => null, 'calibration_stage' => 'new', 'current_raw' => 10000, 'current_tare_raw' => null, 'estimated_count' => null, 'stable' => false, 'calibrated' => false, 'firmware_version' => 'test'];
    $this->postJson('/api/v1/device/readings', $r)->assertOk()->assertJsonPath('accepted', true);
    $this->postJson('/api/v1/device/readings', $r)->assertUnprocessable();
    expect(app(DeviceReadingService::class)->latest($scale)['sequence'])->toBe(0);
    $this->assertDatabaseCount('inventory_counts', 0);
});
test('signed fractional calibration uses ten total pieces and keeps prior versions', function () {
    $f = inventoryScenario();
    expect($f['profile']->raw_units_per_piece)->toBe(-125.5);
    expect($f['profile']->one_piece_net)->toBe(-125.5);
    $f['sim']->send('new');
    foreach (['tare', 'sample_one', 'sample_ten', 'calibrate'] as $action) {
        $f['sim']->send($action);
    }
    $second = app(CalibrationService::class)->save($f['user'], $f['s']->id);
    expect($second->version)->toBe(2);
    expect($f['profile']->fresh()->active)->toBeFalse();
    $this->assertDatabaseCount('calibration_profiles', 2);
});
test('zero non-finite or inconsistent samples are refused', function (float $one, float $ten) {
    expect(fn () => app(CalibrationService::class)->factor(1000, $one, $ten))->toThrow(ValidationException::class);
})->with(['zero' => [1000.0, 1000.0], 'infinite' => [INF, 900.0], 'nan' => [NAN, 900.0], 'wrong sign' => [1010.0, 900.0], 'inconsistent' => [990.0, 0.0]]);
test('positive signed calibration is also accepted', function () {
    expect(app(CalibrationService::class)->factor(100, 112.5, 225)['raw_units_per_piece'])->toBe(12.5);
});
test('profile application requires matching acknowledgement and fresh tare', function () {
    $f = inventoryScenario();
    $c = app(CalibrationService::class)->apply($f['user'], $f['s']->id, $f['profile']->id);
    expect(fn () => app(InventoryCountService::class)->save($f['user'], inventoryInput($f)))->toThrow(ValidationException::class);
    $f['sim']->send('ack');
    expect(fn () => app(InventoryCountService::class)->save($f['user'], inventoryInput($f)))->toThrow(ValidationException::class);
    $f['sim']->send('tare');
    $f['r'] = $f['sim']->send('stable', 8);
    $count = app(InventoryCountService::class)->save($f['user'], inventoryInput($f));
    expect($count->new_quantity)->toBe(8);
});
test('superseded and expired commands cannot be acknowledged', function (bool $expire) {
    $f = inventoryScenario();
    $service = app(CalibrationService::class);
    $c = $service->apply($f['user'], $f['s']->id, $f['profile']->id);
    if ($expire) {
        $this->travel(61)->seconds();
    } else {
        $service->apply($f['user'], $f['s']->id, $f['profile']->id);
    }
    expect(fn () => $service->ack($f['scale'], $c->id, ['boot_id' => $f['r']['boot_id'], 'profile_id' => $f['profile']->id, 'profile_version' => 1, 'calibration_session_id' => (string) Str::uuid(), 'success' => true]))->toThrow(ValidationException::class);
})->with([true, false]);
test('invalid live states never change inventory', function (string $action) {
    $f = inventoryScenario();
    if ($action === 'stale') {
        $this->travel(11)->seconds();
    } else {
        $f['sim']->send($action);
    }
    expect(fn () => app(InventoryCountService::class)->save($f['user'], inventoryInput($f)))->toThrow(ValidationException::class);
    expect($f['medicine']->fresh()->quantity)->toBeNull();
    $this->assertDatabaseCount('inventory_counts', 0);
})->with(['unstable', 'new', 'reboot', 'disconnect', 'stale']);
test('out of order sequences and retired boots cannot take over', function () {
    $f = inventoryScenario();
    expect(fn () => $f['sim']->send('out_of_order'))->toThrow(ValidationException::class);
    $old = $f['r'];
    $f['sim']->send('reboot');
    $old['sequence'] += 100;
    expect(fn () => app(DeviceReadingService::class)->ingest($f['scale'], $old))->toThrow(ValidationException::class);
});
test('sessions are exclusive and can be released or reclaimed after expiry', function () {
    $f = inventoryScenario();
    $other = User::factory()->create();
    $service = app(CountingSessionService::class);
    expect(fn () => $service->acquire($other, $f['medicine'], $f['scale']))->toThrow(ValidationException::class);
    expect(fn () => $service->owned($other, $f['s']->id))->toThrow(ValidationException::class);
    $service->release($f['user'], $f['s']->id);
    $new = $service->acquire($other, $f['medicine'], $f['scale']);
    $this->travel(181)->seconds();
    expect(fn () => $service->owned($other, $new->id))->toThrow(ValidationException::class);
    expect($service->acquire($f['user'], $f['medicine'], $f['scale'])->user_id)->toBe($f['user']->id);
});
test('confirmed count replaces stock exactly once and rejects conflicting idempotency keys', function () {
    $f = inventoryScenario();
    $f['medicine']->update(['quantity' => 120]);
    $d = inventoryInput($f);
    $svc = app(InventoryCountService::class);
    $first = $svc->save($f['user'], $d);
    $retry = $svc->save($f['user'], $d);
    expect($retry->id)->toBe($first->id);
    expect($first->difference)->toBe(-20);
    expect($f['medicine']->fresh()->quantity)->toBe(100);
    $this->assertDatabaseCount('inventory_counts', 1);
    expect(fn () => $svc->save($f['user'], array_merge($d, ['notes' => 'different'])))->toThrow(ValidationException::class);
});
test('first count of zero differs from an uncounted balance and manual counts are audited', function () {
    $user = User::factory()->create();
    $m = inventoryMedicine();
    expect($m->fresh()->quantity)->toBeNull();
    $c = app(InventoryCountService::class)->save($user, ['medicine_id' => $m->id, 'stock_revision' => 0, 'source' => 'manual', 'quantity' => 0, 'reason' => 'Hand counted all stock', 'identity_confirmed' => true, 'idempotency_key' => (string) Str::uuid()]);
    expect($c->previous_quantity)->toBeNull();
    expect($c->difference)->toBeNull();
    expect($c->source)->toBe('manual');
    expect($m->fresh()->quantity)->toBe(0);
});
test('stale revisions and archived medicine reject adjustments without history', function (bool $archived) {
    $f = inventoryScenario();
    $d = inventoryInput($f);
    $f['medicine']->update($archived ? ['active' => false] : ['stock_revision' => 1]);
    expect(fn () => app(InventoryCountService::class)->save($f['user'], $d))->toThrow($archived ? AuthorizationException::class : ValidationException::class);
    $this->assertDatabaseCount('inventory_counts', 0);
})->with([true, false]);
test('browser quantities cannot override the servers scale snapshot', function () {
    $f = inventoryScenario();
    $d = inventoryInput($f, ['quantity' => 999]);
    $count = app(InventoryCountService::class)->save($f['user'], $d);
    expect($count->new_quantity)->toBe(100);
});
test('reviewed snapshot is used instead of silently choosing a later reading', function () {
    $f = inventoryScenario();
    $d = inventoryInput($f);
    $f['sim']->send('stable', 110);
    $c = app(InventoryCountService::class)->save($f['user'], $d);
    expect($c->new_quantity)->toBe(100);
});
test('simulation cannot target real inventory and is excluded outside local testing', function () {
    $f = inventoryScenario();
    $real = inventoryMedicine();
    expect(fn () => app(CountingSessionService::class)->acquire($f['user'], $real, $f['scale']))->toThrow(ValidationException::class);
    app()->detectEnvironment(fn () => 'production');
    $this->actingAs($f['user'])->get('/simulator')->assertForbidden();
    expect(fn () => app(SimulatorService::class)->send('reboot'))->toThrow(HttpException::class);
    $this->assertDatabaseCount('inventory_counts', 0);
});
test('staff screens render on desktop and count review can save a manual balance', function () {
    $staff = User::factory()->create();
    $m = inventoryMedicine();
    foreach (['dashboard', 'medicines', 'count-stock', 'calibration-profiles', 'count-history'] as $page) {
        $this->actingAs($staff)->get('/'.$page)->assertOk();
    }
    Livewire::actingAs($staff)->test('pages::count-stock')->set('medicineId', (string) $m->id)->set('method', 'manual')->set('manualQuantity', 12)->set('identityConfirmed', true)->call('review')->assertHasNoErrors()->call('confirm')->assertHasNoErrors();
    $this->assertDatabaseHas('medicines', ['id' => $m->id, 'quantity' => 12]);
});

test('scale workflow review and confirmation work through the livewire screen', function () {
    $f = inventoryScenario();
    Livewire::actingAs($f['user'])->test('pages::count-stock')
        ->set('simulation', true)->set('medicineId', (string) $f['medicine']->id)
        ->call('acquire')->set('profileId', (string) $f['profile']->id)->set('identityConfirmed', true)->call('review')
        ->assertHasNoErrors()->assertSet('showReview', true)->call('confirm')->assertHasNoErrors();
    $this->assertDatabaseHas('inventory_counts', ['medicine_id' => $f['medicine']->id, 'new_quantity' => 100, 'source' => 'simulation', 'count_method' => 'scale']);
});

test('a reused tare cannot satisfy fresh tare after profile application', function () {
    $f = inventoryScenario();
    app(CalibrationService::class)->apply($f['user'], $f['s']->id, $f['profile']->id);
    $r = $f['sim']->send('ack');
    $r['sequence']++;
    $r['calibration_stage'] = 'ready';
    $r['tare_revision'] = $f['r']['tare_revision'];
    $r['current_tare_raw'] = 100000;
    $r['estimated_count'] = 0;
    expect(fn () => app(DeviceReadingService::class)->ingest($f['scale'], $r))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('inventory_counts', 0);
});

test('mismatching estimates cannot be confirmed even with stable telemetry', function () {
    $f = inventoryScenario();
    $r = $f['r'];
    $r['sequence']++;
    $r['estimated_count'] = 999;
    $f['r'] = app(DeviceReadingService::class)->ingest($f['scale'], $r);
    expect(fn () => app(InventoryCountService::class)->save($f['user'], inventoryInput($f)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('inventory_counts', 0);
});

test('a saved count remains idempotent after releasing its scale session', function () {
    $f = inventoryScenario();
    $d = inventoryInput($f);
    $svc = app(InventoryCountService::class);
    $first = $svc->save($f['user'], $d);
    app(CountingSessionService::class)->release($f['user'], $f['s']->id);
    expect($svc->save($f['user'], $d)->id)->toBe($first->id);
    $this->assertDatabaseCount('inventory_counts', 1);
});

test('an audit insertion failure rolls back the balance update', function () {
    $user = User::factory()->create();
    $m = inventoryMedicine();
    DB::statement("CREATE TRIGGER reject_count BEFORE INSERT ON inventory_counts BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    expect(fn () => app(InventoryCountService::class)->save($user, ['medicine_id' => $m->id, 'stock_revision' => 0, 'source' => 'manual', 'quantity' => 5, 'reason' => 'Manual recount', 'identity_confirmed' => true, 'idempotency_key' => (string) Str::uuid()]))->toThrow(QueryException::class);
    expect($m->fresh()->quantity)->toBeNull();
    expect($m->fresh()->stock_revision)->toBe(0);
    $this->assertDatabaseCount('inventory_counts', 0);
});

test('calibration captures cannot be reassociated to another session', function () {
    $f = inventoryScenario();
    $capture = ['device_id' => $f['scale']->device_identifier, 'counting_session_id' => (string) Str::uuid(), 'boot_id' => $f['r']['boot_id'], 'sequence' => $f['r']['sequence'], 'calibration_session_id' => $f['r']['calibration_session_id'], 'tare_raw' => 100000, 'one_piece_raw' => 99874.5, 'ten_piece_raw' => 98745];
    expect(fn () => app(CalibrationService::class)->capture($f['scale'], $capture))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('calibration_profiles', 1);
});

test('acknowledgements reject a wrong profile and repeat safely when identical', function () {
    $f = inventoryScenario();
    $svc = app(CalibrationService::class);
    $ack = ['boot_id' => $f['r']['boot_id'], 'profile_id' => $f['profile']->id, 'profile_version' => 1, 'calibration_session_id' => $f['r']['calibration_session_id'], 'success' => true];
    $svc->ack($f['scale'], $f['command']->id, $ack);
    expect(fn () => $svc->ack($f['scale'], $f['command']->id, array_merge($ack, ['profile_version' => 9])))->toThrow(ValidationException::class);
    expect($f['command']->fresh()->status)->toBe('acknowledged');
});

test('a physical NEW supersedes a pending profile command and clears medicine calibration association', function () {
    $f = inventoryScenario();
    $c = app(CalibrationService::class)->apply($f['user'], $f['s']->id, $f['profile']->id);
    $f['sim']->send('new');
    expect($c->fresh()->status)->toBe('superseded');
    expect($f['s']->fresh()->calibration_profile_id)->toBeNull();
    expect($f['s']->fresh()->acknowledged)->toBeFalse();
});

test('a simulated tare and calibration cannot leak into a physical medicine count', function () {
    $f = inventoryScenario();
    $real = inventoryMedicine();
    $d = inventoryInput($f, ['medicine_id' => $real->id]);
    expect(fn () => app(InventoryCountService::class)->save($f['user'], $d))->toThrow(ValidationException::class);
    expect($real->fresh()->quantity)->toBeNull();
});

test('manual counts require a quantity reason and confirmed identity', function () {
    $user = User::factory()->create();
    $m = inventoryMedicine();
    expect(fn () => app(InventoryCountService::class)->save($user, ['medicine_id' => $m->id, 'stock_revision' => 0, 'source' => 'manual', 'quantity' => 2, 'reason' => '', 'identity_confirmed' => false, 'idempotency_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('inventory_counts', 0);
});

test('physical device API delivers an acknowledged profile and a fresh tare before physical stock save', function () {
    $f = inventoryScenario();
    $f['scale']->update(['simulation' => false, 'credential_hash' => Hash::make('physical-test-secret')]);
    $f['medicine']->update(['simulation' => false]);
    $c = app(CalibrationService::class)->apply($f['user'], $f['s']->id, $f['profile']->id);
    $this->withHeaders(['X-Device-ID' => $f['scale']->device_identifier, 'Authorization' => 'Bearer physical-test-secret']);
    $this->getJson('/api/v1/device/commands')->assertOk()->assertJsonPath('active_session.id', $f['s']->id)->assertJsonPath('commands.0.command_id', $c->id)->assertJsonPath('commands.0.raw_units_per_piece', -125.5);
    $epoch = (string) Str::uuid();
    $this->postJson('/api/v1/device/commands/'.$c->id.'/ack', ['device_id' => $f['scale']->device_identifier, 'boot_id' => $f['r']['boot_id'], 'profile_id' => $f['profile']->id, 'profile_version' => 1, 'calibration_session_id' => $epoch, 'success' => true])->assertOk()->assertJsonPath('acknowledged', true);
    $r = $f['r'];
    unset($r['received_at'],$r['scale_id']);
    $r = array_merge($r, ['sequence' => $r['sequence'] + 1, 'calibration_session_id' => $epoch, 'tare_revision' => null, 'current_tare_raw' => null, 'estimated_count' => null, 'calibration_stage' => 'tare_required']);
    $this->postJson('/api/v1/device/readings', $r)->assertOk();
    $r = array_merge($r, ['sequence' => $r['sequence'] + 1, 'tare_revision' => (string) Str::uuid(), 'current_tare_raw' => 100000, 'current_raw' => 94729, 'estimated_count' => 42, 'calibration_stage' => 'ready']);
    $this->postJson('/api/v1/device/readings', $r)->assertOk();
    $f['r'] = $r;
    $count = app(InventoryCountService::class)->save($f['user'], inventoryInput($f));
    expect($count->source)->toBe('physical_scale');
    expect($count->new_quantity)->toBe(42);
    $this->assertDatabaseCount('inventory_counts', 1);
});

test('device payloads are limited and deployed insecure transport is rejected', function () {
    $scale = Scale::create(['device_identifier' => 'test-scale', 'name' => 'Test scale', 'credential_hash' => Hash::make('secret')]);
    $this->withHeaders(['X-Device-ID' => 'test-scale', 'Authorization' => 'Bearer secret'])->postJson('/api/v1/device/readings', ['padding' => str_repeat('x', 8192)])->assertStatus(413);
    app()->detectEnvironment(fn () => 'production');
    $this->getJson('/api/v1/device/commands')->assertForbidden();
});

test('simulator flag must be explicitly enabled even in local testing', function () {
    config(['inventory.simulation' => false]);
    $this->actingAs(User::factory()->create())->get('/simulator')->assertForbidden();
});

test('private administrator can be bootstrapped without a seeded password', function () {
    $this->artisan('inventory:create-admin')
        ->expectsQuestion('Administrator name', 'Owner')
        ->expectsQuestion('Email', 'owner@example.test')
        ->expectsQuestion('Password (at least 12 characters)', 'A-private-password')
        ->assertSuccessful();
    $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'admin', 'active' => true]);
});

test('simulated stale state cannot use a recently cached snapshot to bypass freshness', function () {
    $f = inventoryScenario();
    $f['sim']->send('stale');
    expect(fn () => app(InventoryCountService::class)->save($f['user'], inventoryInput($f)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('inventory_counts', 0);
});

test('database prevents two active profile versions for the same medicine and scale', function () {
    $f = inventoryScenario();
    expect(fn () => $f['profile']->replicate()->forceFill(['version' => 2])->save())->toThrow(QueryException::class);
    $this->assertDatabaseCount('calibration_profiles', 1);
});
