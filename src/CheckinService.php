<?php
declare(strict_types=1);

class CheckinService
{
    public function __construct(private PDO $db) {}

    /**
     * @throws RuntimeException on duplicate check-in
     */
    public function checkin(string $sessionUid, string $nickname): void
    {
        $attendeeId = $this->findOrCreate($nickname);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO checkins (id, session_uid, attendee_id, created_at)
                VALUES (?, ?, ?, datetime('now'))
            ");
            $stmt->execute([uuid4(), $sessionUid, $attendeeId]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new RuntimeException('Already checked in for this session', 409);
            }
            throw $e;
        }
    }

    /**
     * @throws RuntimeException (404) if no check-in found
     */
    public function cancel(string $sessionUid, string $nickname): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM checkins
            WHERE session_uid = ? AND attendee_id = (
                SELECT id FROM attendees WHERE nickname = ?
            )
        ");
        $stmt->execute([$sessionUid, $nickname]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No check-in found for this session', 404);
        }
    }

    private function findOrCreate(string $nickname): string
    {
        $stmt = $this->db->prepare('SELECT id FROM attendees WHERE nickname = ?');
        $stmt->execute([$nickname]);
        $row = $stmt->fetch();

        if ($row) return $row['id'];

        $id = uuid4();
        $this->db->prepare('INSERT INTO attendees (id, nickname) VALUES (?, ?)')->execute([$id, $nickname]);
        return $id;
    }
}
