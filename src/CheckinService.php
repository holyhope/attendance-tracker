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
                INSERT INTO checkins (id, session_uid, attendee_id, checked_in_by, created_at)
                VALUES (?, ?, ?, NULL, datetime('now'))
            ");
            $stmt->execute([uuid4(), $sessionUid, $attendeeId]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new RuntimeException('Already checked in for this session', 409);
            }
            throw $e;
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
