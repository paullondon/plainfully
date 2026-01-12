<?php declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;

$pdo = pf_db();
$ai  = pf_ai_client();
$engine = new CheckEngine($pdo, $ai);

while (true) {
    $pdo->beginTransaction();

    $row = $pdo->query("
        SELECT * FROM inbound_queue
        WHERE status = 'queued'
        ORDER BY id
        LIMIT 1
        FOR UPDATE
    ")->fetch();

    if (!$row) {
        $pdo->commit();
        sleep(2);
        continue;
    }

    $pdo->prepare("
        UPDATE inbound_queue SET status = 'processing' WHERE id = :id
    ")->execute([':id' => $row['id']]);

    $input = new CheckInput(
        $row['channel'],
        $row['source_identifier'],
        $row['content_type'],
        $row['raw_content'],
        $row['channel'] === 'email' ? $row['source_identifier'] : null,
        $row['channel'] === 'sms' ? $row['source_identifier'] : null,
        ['trace_id' => $row['trace_id']]
    );

    $result = $engine->run($input, false);

    // enqueue outbound
    $pdo->prepare("
        INSERT INTO outbound_queue
        (check_id, channel, destination, subject, body_text)
        VALUES
        (:cid, :ch, :dest, :subj, :body)
    ")->execute([
        ':cid'  => $result->id,
        ':ch'   => $row['channel'],
        ':dest' => $row['source_identifier'],
        ':subj' => 'Plainfully result',
        ':body' => $result->shortVerdict,
    ]);

    $pdo->prepare("
        UPDATE inbound_queue SET status = 'done' WHERE id = :id
    ")->execute([':id' => $row['id']]);

    $pdo->commit();
}
