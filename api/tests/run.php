<?php
/**
 * Sunwise scheduler tests.
 *
 * Plain PHP, no dependencies. Each test gets a fresh SQLite database.
 * Run from the repo root:  php api/tests/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbFile = tempnam(sys_get_temp_dir(), 'sunwise-test-');
unlink($dbFile);
putenv('SUNWISE_DB_PATH=' . $dbFile);
putenv('APP_ENV=testing');
putenv('CLAUDE_API_KEY=');
putenv('JWT_SECRET=test-secret-that-is-long-enough');

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

spl_autoload_register(function ($class) {
    if (in_array($class, ['AIServiceFactory', 'AIServiceInterface'], true)) {
        require_once __DIR__ . '/../services/AIService.php';
        return;
    }
    foreach (['controllers', 'services', 'middleware'] as $dir) {
        $file = __DIR__ . "/../{$dir}/{$class}.php";
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ---------------------------------------------------------------------
// Tiny test framework
// ---------------------------------------------------------------------

$tests = [];
$failures = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true));
    }
}

/**
 * Wipe all rows between tests (schema stays).
 */
function resetData(): void
{
    $db = db();
    $db->exec('PRAGMA foreign_keys = OFF');
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'schema_migrations'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $db->exec("DELETE FROM \"{$table}\"");
    }
    $db->exec('PRAGMA foreign_keys = ON');
    Clock::useTimezone('America/Denver');
}

function makeUser(array $fields = []): int
{
    $fields += ['email' => 'owner' . mt_rand() . '@example.com', 'timezone' => 'America/Denver', 'fertilizer_method' => 'separate', 'winter_rest' => 1];
    $cols = implode(', ', array_keys($fields));
    $marks = implode(', ', array_fill(0, count($fields), '?'));
    db()->prepare("INSERT INTO users ({$cols}) VALUES ({$marks})")->execute(array_values($fields));
    return (int) db()->lastInsertId();
}

function makePlant(int $userId, array $fields = []): int
{
    $fields += ['user_id' => $userId, 'name' => 'Fern ' . mt_rand(), 'species' => 'Boston fern'];
    $cols = implode(', ', array_keys($fields));
    $marks = implode(', ', array_fill(0, count($fields), '?'));
    db()->prepare("INSERT INTO plants ({$cols}) VALUES ({$marks})")->execute(array_values($fields));
    return (int) db()->lastInsertId();
}

function makeTask(int $plantId, string $type, string $dueDate, int $interval = 7, array $fields = []): int
{
    $fields += [
        'plant_id' => $plantId,
        'task_type' => $type,
        'due_date' => $dueDate,
        'recurrence' => json_encode(['type' => 'days', 'interval' => $interval]),
        'priority' => 'normal',
        'instructions' => "Do the {$type}",
    ];
    $cols = implode(', ', array_keys($fields));
    $marks = implode(', ', array_fill(0, count($fields), '?'));
    db()->prepare("INSERT INTO tasks ({$cols}) VALUES ({$marks})")->execute(array_values($fields));
    return (int) db()->lastInsertId();
}

function logCare(int $plantId, string $action, string $utc): void
{
    db()->prepare('INSERT INTO care_log (plant_id, action, performed_at) VALUES (?, ?, ?)')->execute([$plantId, $action, $utc]);
}

function pending(int $plantId, string $type): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE plant_id = ? AND task_type = ? AND completed_at IS NULL AND skipped_at IS NULL');
    $stmt->execute([$plantId, $type]);
    return $stmt->fetchAll();
}

function task(int $id): array
{
    return CareScheduler::loadTask($id);
}

function logCount(int $plantId, ?string $action = null): int
{
    $sql = 'SELECT COUNT(*) FROM care_log WHERE plant_id = ?';
    $params = [$plantId];
    if ($action) {
        $sql .= ' AND action = ?';
        $params[] = $action;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * A date outside the fertilizer rest months, so results don't depend on
 * the day the tests run.
 */
function growingSeasonDate(): string
{
    $today = Clock::today();
    return Clock::isRestMonth($today) ? Clock::nextMarchFirst($today) : $today;
}

// ---------------------------------------------------------------------
// Migrations
// ---------------------------------------------------------------------

test('migrations are tracked and safe to run again', function () {
    Database::runMigrations();
    Database::runMigrations();
    $count = (int) db()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    same(count(glob(__DIR__ . '/../migrations/*.sql')), $count, 'every migration file is recorded once');
});

test('an older database without tracking catches up without errors', function () {
    db()->exec('DELETE FROM schema_migrations');
    Database::runMigrations();
    check(!Database::hasPendingMigrations(), 'no migrations left pending');
});

test('statement splitter ignores semicolons in comments and quotes', function () {
    $parts = Database::splitStatements("-- a; comment\nCREATE TABLE a (x TEXT DEFAULT 'a;b');\nINSERT INTO a VALUES ('c');");
    same(2, count($parts), 'two statements');
    check(str_contains($parts[0], "'a;b'"), 'quoted semicolon kept');
});

// ---------------------------------------------------------------------
// Completing, undoing, skipping
// ---------------------------------------------------------------------

test('completing a water task logs it once and schedules the next one', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $id = makeTask($plant, 'water', Clock::today(), 7);
    $scheduler = new CareScheduler();

    $scheduler->complete(task($id), $user);
    $scheduler->complete(task($id), $user); // double tap

    same(1, logCount($plant, 'water'), 'one care log entry');
    $next = pending($plant, 'water');
    same(1, count($next), 'one follow-up task');
    same(Clock::daysFromToday(7), $next[0]['due_date'], 'follow-up is a full interval out');
    same($id, (int) $next[0]['generated_from_task_id'], 'follow-up links back for undo');
    same('Do the water', $next[0]['instructions'], 'instructions carried over');
});

test('undo puts the task back and removes the follow-up and log entry', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $id = makeTask($plant, 'water', Clock::today(), 7);
    $scheduler = new CareScheduler();

    $scheduler->complete(task($id), $user);
    $scheduler->uncomplete(task($id));

    check(task($id)['completed_at'] === null, 'task is pending again');
    same(1, count(pending($plant, 'water')), 'only the original task is pending');
    same(0, logCount($plant, 'water'), 'care log entry removed');
});

test('granules: fertilizing does not skip watering', function () {
    $user = makeUser(['fertilizer_method' => 'separate']);
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::daysFromToday(2), 7);
    $fert = makeTask($plant, 'fertilize', growingSeasonDate(), 120);

    (new CareScheduler())->complete(task($fert), $user);

    check(task($water)['skipped_at'] === null, 'water task untouched');
});

test('liquid: fertilizing resets watering, and undo restores it', function () {
    $user = makeUser(['fertilizer_method' => 'mixed']);
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::daysFromToday(2), 7);
    $fert = makeTask($plant, 'fertilize', growingSeasonDate(), 30);
    $scheduler = new CareScheduler();

    $scheduler->complete(task($fert), $user);
    check(task($water)['skipped_at'] !== null, 'water task auto-skipped');
    same($fert, (int) task($water)['skipped_by_task_id'], 'skip remembers which task caused it');
    $newWater = pending($plant, 'water');
    same(Clock::daysFromToday(7), $newWater[0]['due_date'], 'next watering is a full interval from today');

    $scheduler->uncomplete(task($fert));
    check(task($water)['skipped_at'] === null, 'water task restored');
    same(1, count(pending($plant, 'water')), 'reset water task removed');
});

test('skip brings a water task back tomorrow, bulk skip too', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $a = makeTask($plant, 'water', Clock::today(), 7);
    $controller = new TaskController();

    $controller->bulkSkip([], ['task_ids' => [$a]], $user);
    same(Clock::daysFromToday(1), pending($plant, 'water')[0]['due_date'], 'bulk skip uses the short skip delay');
});

test('bulk complete leaves check tasks for the check form', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::today(), 7);
    $check = makeTask($plant, 'check', Clock::today(), 7);

    $result = (new TaskController())->bulkComplete([], ['task_ids' => [$water, $check]], $user);

    same([$water], $result['completed'], 'water completed');
    same([$check], $result['skipped_check'], 'check left alone');
    check(task($check)['completed_at'] === null, 'check task still pending');
});

test('bulk complete runs the same follow-up rules as a single complete', function () {
    $user = makeUser(['fertilizer_method' => 'mixed']);
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::daysFromToday(1), 7);
    $fert = makeTask($plant, 'fertilize', growingSeasonDate(), 30);

    (new TaskController())->bulkComplete([], ['task_ids' => [$fert]], $user);

    check(task($water)['skipped_at'] !== null, 'liquid fertilizer reset the water task');
});

test('snooze moves the due date without logging a skip', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $id = makeTask($plant, 'mist', Clock::today(), 3);
    $controller = new TaskController();

    $result = $controller->snooze(['id' => $id], ['days' => 3], $user);
    same(Clock::daysFromToday(3), $result['task']['due_date'], 'moved 3 days');
    same(0, logCount($plant), 'nothing logged');

    $bad = $controller->snooze(['id' => $id], ['date' => Clock::today()], $user);
    same(400, $bad['status'], 'today is not a valid snooze date');
});

test('other users cannot touch your tasks', function () {
    $owner = makeUser();
    $stranger = makeUser();
    $plant = makePlant($owner);
    $id = makeTask($plant, 'water', Clock::today(), 7);

    $result = (new TaskController())->complete(['id' => $id], [], $stranger);
    same(404, $result['status'], 'stranger gets not found');
    check(task($id)['completed_at'] === null, 'task untouched');
});

// ---------------------------------------------------------------------
// Fertilizer rules
// ---------------------------------------------------------------------

test('winter rest moves fertilizing to March 1', function () {
    $user = makeUser(['winter_rest' => 1]);
    $plant = makePlant($user);
    $scheduler = new CareScheduler();
    $t = ['task_type' => 'fertilize', 'plant_id' => $plant];

    same('2027-03-01', $scheduler->normalizeDueDate($t, '2026-11-20'), 'November moves to March');
    same('2027-03-01', $scheduler->normalizeDueDate($t, '2027-02-10'), 'February moves to March');
    same('2027-06-10', $scheduler->normalizeDueDate($t, '2027-06-10'), 'June stays');
    same('2026-11-20', $scheduler->normalizeDueDate(['task_type' => 'water', 'plant_id' => $plant], '2026-11-20'), 'water is never moved');
});

test('winter rest off keeps winter dates', function () {
    $user = makeUser(['winter_rest' => 0]);
    $plant = makePlant($user);
    same('2026-11-20', (new CareScheduler())->normalizeDueDate(['task_type' => 'fertilize', 'plant_id' => $plant], '2026-11-20'), 'date unchanged');
});

test('granules repeat about every 4 months even if the plan said 30 days', function () {
    $user = makeUser(['fertilizer_method' => 'separate']);
    $plant = makePlant($user);
    $id = makeTask($plant, 'fertilize', growingSeasonDate(), 30);
    same(120, (new CareScheduler())->intervalFor(task($id)), 'interval stretched to 120 days');
});

test('no fertilizing for 3 months after a repot', function () {
    $user = makeUser(['winter_rest' => 0]);
    $plant = makePlant($user);
    logCare($plant, 'repot', Clock::utcNow());
    $date = (new CareScheduler())->normalizeDueDate(['task_type' => 'fertilize', 'plant_id' => $plant], Clock::daysFromToday(10));
    same(Clock::daysFromToday(90), $date, 'pushed to 90 days after the repot');
});

// ---------------------------------------------------------------------
// Daily cleanup (reconcile)
// ---------------------------------------------------------------------

test('turning off fertilize no longer blocks watering (the old bug)', function () {
    $user = makeUser(['fertilizer_method' => 'mixed']);
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::today(), 7);
    $fert = makeTask($plant, 'fertilize', Clock::daysFromToday(-5), 30);
    db()->prepare('INSERT INTO task_type_settings (user_id, task_type, enabled) VALUES (?, ?, 0)')->execute([$user, 'fertilize']);

    $result = (new TaskController())->today([], [], $user);

    check(task($water)['skipped_at'] === null, 'water task was not skipped');
    check(task($fert)['skipped_at'] !== null, 'hidden fertilize task was retired');
    $types = array_column($result['tasks'], 'task_type');
    same(['water'], $types, 'Today shows the water task');
});

test('recent watering pushes a due water task out', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $id = makeTask($plant, 'water', Clock::today(), 7);
    $twoDaysAgo = Clock::daysFromToday(-2);
    logCare($plant, 'water', $twoDaysAgo . ' 18:00:00');

    (new CareScheduler())->reconcile($user);

    $lastLocal = Clock::utcToLocalDate($twoDaysAgo . ' 18:00:00');
    same(Clock::addDays($lastLocal, 7), task($id)['due_date'], 'due a week after the last watering');
});

test('granules: a fertilize log does not count as watering', function () {
    $user = makeUser(['fertilizer_method' => 'separate']);
    $plant = makePlant($user);
    $id = makeTask($plant, 'water', Clock::today(), 7);
    logCare($plant, 'fertilize', Clock::utcNow());

    (new CareScheduler())->reconcile($user);

    same(Clock::today(), task($id)['due_date'], 'water still due today');
});

test('liquid: water and fertilize due together shows only fertilize', function () {
    $user = makeUser(['fertilizer_method' => 'mixed']);
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::today(), 7);
    makeTask($plant, 'fertilize', Clock::today(), 30, ['due_date' => Clock::today()]);
    db()->prepare('UPDATE users SET winter_rest = 0 WHERE id = ?')->execute([$user]);

    (new CareScheduler())->reconcile($user);

    check(task($water)['skipped_at'] !== null, 'water skipped in favor of fertilize');
    same(1, count(pending($plant, 'water')), 'a new water task exists');
    check(pending($plant, 'water')[0]['care_plan_id'] === task($water)['care_plan_id'], 'new task keeps its plan link');
    same('Do the water', pending($plant, 'water')[0]['instructions'], 'new task keeps its instructions');
});

test('pending winter fertilize tasks move to March on the next load', function () {
    $user = makeUser(['winter_rest' => 1]);
    $plant = makePlant($user);
    $id = makeTask($plant, 'fertilize', '2026-12-05', 120);

    (new CareScheduler())->reconcile($user);

    same('2027-03-01', task($id)['due_date'], 'moved to March 1');
});

// ---------------------------------------------------------------------
// Today list
// ---------------------------------------------------------------------

test('Today shows tasks finished today but not earlier ones', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $old = makeTask($plant, 'mist', Clock::daysFromToday(-10), 3, ['completed_at' => Clock::daysFromToday(-9) . ' 15:00:00']);
    $done = makeTask($plant, 'rotate', Clock::today(), 7);
    $late = makeTask($plant, 'water', Clock::daysFromToday(-3), 7);
    (new CareScheduler())->complete(task($done), $user);

    $tasks = (new CareScheduler())->tasksForToday($user, true);
    $ids = array_map('intval', array_column($tasks, 'id'));

    check(!in_array($old, $ids, true), 'old completion hidden');
    check(in_array($done, $ids, true), "today's completion shown");
    $lateRow = array_values(array_filter($tasks, fn($t) => (int) $t['id'] === $late))[0];
    same(3, $lateRow['days_overdue'], 'late task shows 3 days overdue');
    same(7, $lateRow['interval_days'], 'interval included');
});

// ---------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------

test('wet soil holds off watering longer the wetter it is', function () {
    same(2, TaskController::wetSoilDelay(7, 7), '7/10 on a 7-day plant');
    same(4, TaskController::wetSoilDelay(8, 7), '8/10');
    same(5, TaskController::wetSoilDelay(9, 7), '9/10');
});

test('a wet check skips today\'s watering with a longer delay', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::today(), 10);
    $check = makeTask($plant, 'check', Clock::today(), 7);

    (new TaskController())->complete(['id' => $check], ['check_data' => ['moisture_level' => 8, 'general_health' => 4]], $user);

    check(task($water)['skipped_at'] !== null, 'water skipped');
    same(Clock::daysFromToday(5), pending($plant, 'water')[0]['due_date'], 'next watering in half the interval');
});

test('a bone-dry check pulls watering to today', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $water = makeTask($plant, 'water', Clock::daysFromToday(4), 7);
    $check = makeTask($plant, 'check', Clock::today(), 7);

    (new TaskController())->complete(['id' => $check], ['check_data' => ['moisture_level' => 1, 'general_health' => 4]], $user);

    same(Clock::today(), task($water)['due_date'], 'watering moved to today');
});

test('check readings reach the care plan prompt', function () {
    $prompt = CarePlanPrompt::build(
        ['species' => 'Pothos', 'climate_notes' => 'Colorado Front Range, dry winter air', 'fertilizer_method' => 'separate', 'winter_rest' => true],
        [['action' => 'check', 'performed_at' => '2026-11-10 15:00:00', 'notes' => null, 'outcome' => null, 'check_data' => json_encode(['moisture_level' => 3, 'general_health' => 4])]],
        'fall'
    );
    check(str_contains($prompt, 'Moisture: 3/10'), 'moisture reading included');
    check(str_contains($prompt, 'Colorado Front Range'), 'climate notes included');
    check(str_contains($prompt, 'Osmocote'), 'fertilizer method included');
});

// ---------------------------------------------------------------------
// Care plans, overrides, settings
// ---------------------------------------------------------------------

test('care plan without AI uses the default plan with Osmocote timing', function () {
    $user = makeUser(['fertilizer_method' => 'separate', 'winter_rest' => 1]);
    $plant = makePlant($user);

    $plan = (new CarePlanController())->generateCarePlan($plant, $user);

    check($plan !== null, 'plan created');
    same(1, count(pending($plant, 'water')), 'water task exists');
    same(1, count(pending($plant, 'check')), 'check task exists');
    $fert = pending($plant, 'fertilize')[0];
    same(120, CareScheduler::recurrenceDays($fert['recurrence']), 'fertilize every 120 days');
    check(!Clock::isRestMonth($fert['due_date']), 'fertilize not scheduled in a rest month');
});

test('hand-set intervals survive a care plan rebuild', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $scheduler = new CareScheduler();
    $scheduler->setOverride($plant, 'water', 9);

    (new CarePlanController())->generateCarePlan($plant, $user);

    same(9, CareScheduler::recurrenceDays(pending($plant, 'water')[0]['recurrence']), 'rebuild kept the 9-day interval');
});

test('override is kept between 1 and 365 days', function () {
    $user = makeUser();
    $plant = makePlant($user);
    same(1, (new CareScheduler())->setOverride($plant, 'water', -4), 'negative becomes 1');
    same(365, (new CareScheduler())->setOverride($plant, 'water', 9999), 'huge becomes 365');
});

test('chat schedule action rejects unknown task types', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $result = (new ChatController())->applyAction(['id' => $plant], ['action' => [
        'type' => 'update_care_schedule',
        'task_type' => "ai_reasoning=(SELECT 1) WHERE 1 /*",
        'frequency_days' => 3,
    ]], $user);
    same(400, $result['status'] ?? null, 'rejected');
});

test('switching to granules re-times pending fertilize tasks', function () {
    $user = makeUser(['fertilizer_method' => 'mixed', 'winter_rest' => 1]);
    $plant = makePlant($user);
    $id = makeTask($plant, 'fertilize', '2026-12-01', 30);

    (new SettingsController())->updateCareSettings([], ['fertilizer_method' => 'separate'], $user);

    same(120, CareScheduler::recurrenceDays(task($id)['recurrence']), 'interval now 120');
    same('2027-03-01', task($id)['due_date'], 'moved out of the rest months');
});

test('turning a task type off and back on restarts it', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $id = makeTask($plant, 'mist', Clock::today(), 3);
    $settings = new SettingsController();

    $settings->updateTaskTypes([], ['settings' => ['mist' => false]], $user);
    check(task($id)['skipped_at'] !== null, 'pending mist retired');

    $settings->updateTaskTypes([], ['settings' => ['mist' => true]], $user);
    same(1, count(pending($plant, 'mist')), 'mist schedule restarted');
});

test('care settings validate the time zone', function () {
    $user = makeUser();
    $bad = (new SettingsController())->updateCareSettings([], ['timezone' => 'Mars/Olympus'], $user);
    same(400, $bad['status'], 'unknown zone rejected');
    $good = (new SettingsController())->updateCareSettings([], ['timezone' => 'America/Denver', 'climate_notes' => 'Dry'], $user);
    same('America/Denver', $good['timezone'], 'zone saved');
});

// ---------------------------------------------------------------------
// Manual care log
// ---------------------------------------------------------------------

test('logging "Watered" completes the water task once', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $id = makeTask($plant, 'water', Clock::today(), 7);

    $result = (new CareLogController())->store(['id' => $plant], ['action' => 'water'], $user);

    same($id, $result['completed_task_id'], 'water task completed');
    same(1, logCount($plant, 'water'), 'only one log entry');
    same(1, count(pending($plant, 'water')), 'next water task scheduled');
});

test('logging a past date stores it in UTC', function () {
    $user = makeUser();
    $plant = makePlant($user);
    $yesterday = Clock::daysFromToday(-1);

    $result = (new CareLogController())->store(['id' => $plant], ['action' => 'note', 'performed_at' => $yesterday], $user);

    same($yesterday, Clock::utcToLocalDate($result['care_log_entry']['performed_at']), 'logged on the chosen day');
    $future = (new CareLogController())->store(['id' => $plant], ['action' => 'note', 'performed_at' => Clock::daysFromToday(2)], $user);
    same(400, $future['status'], 'future dates rejected');
});

// ---------------------------------------------------------------------
// Time and push
// ---------------------------------------------------------------------

test('a local day maps to the right UTC range', function () {
    Clock::useTimezone('America/Denver');
    same(['2026-11-15 07:00:00', '2026-11-16 07:00:00'], Clock::localDayUtcRange('2026-11-15'), 'Denver is UTC-7 in November');
    same('2026-11-15', Clock::utcToLocalDate('2026-11-16 03:00:00'), '8 PM Denver is still the 15th');
});

test('VAPID tokens are valid ES256 signatures', function () {
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key);
    $public = PushService::base64UrlEncode("\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT));
    $private = PushService::base64UrlEncode(str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT));

    $pem = PushService::privateKeyPem($public, $private);
    check(openssl_pkey_get_private($pem) !== false, 'PEM built from raw keys loads');

    $input = 'header.claims';
    openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256);
    $raw = PushService::derToRawSignature($der);
    same(64, strlen($raw), 'raw signature is 64 bytes');

    // Rebuild DER from raw and verify with the public key
    $int = function ($bytes) {
        $bytes = ltrim($bytes, "\0");
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\0" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    };
    $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    $rebuilt = "\x30" . chr(strlen($body)) . $body;
    same(1, openssl_verify($input, $rebuilt, $details['key'], OPENSSL_ALGO_SHA256), 'signature verifies');
});

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------

db(); // create the database and run migrations

$passed = 0;
foreach ($tests as $name => $fn) {
    resetData();
    try {
        $fn();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failures[] = $name;
        echo "  FAIL {$name}\n      " . $e->getMessage() . "\n";
        if (!($e instanceof RuntimeException)) {
            echo '      at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

echo "\n{$passed} passed, " . count($failures) . " failed\n";
exit(empty($failures) ? 0 : 1);
