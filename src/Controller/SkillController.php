<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\SkillService;

/** Skill details for the hover popup (app.js). Static SDE data, the same for everyone. */
final class SkillController
{
    public function __construct(private readonly SkillService $skills) {}

    /** /skill/{typeID}.json */
    public function json(int $skillId): void
    {
        $skill = $this->skills->getSkill($skillId);
        header('Content-Type: application/json; charset=utf-8');
        if ($skill === null) {
            http_response_code(404);
            echo json_encode(['error' => 'not_a_skill']);
            return;
        }
        header('Cache-Control: public, max-age=86400');
        echo json_encode($skill, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
