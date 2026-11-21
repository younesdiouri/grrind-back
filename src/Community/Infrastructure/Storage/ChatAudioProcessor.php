<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Storage;

use App\Community\Application\PreparedChatAttachment;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Le preset natif HIGH_QUALITY d'Expo produit du MPEG-4/AAC. On mesure le PCM décodé
 * et on ne conserve que les échantillons de voix, sans pistes annexes ni métadonnées.
 *
 * @see https://symfony.com/doc/current/components/process.html
 */
final readonly class ChatAudioProcessor
{
    private const int SAMPLE_RATE = 16000;
    private const int MAX_SAMPLES = self::SAMPLE_RATE * 60;

    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function prepare(UploadedFile $upload): PreparedChatAttachment
    {
        $violations = $this->validator->validate($upload, new File(maxSize: 2097152));
        if (\count($violations) > 0) {
            throw new ValidationFailedException($upload, $violations);
        }
        $contents = $upload->getContent();
        if ('ftyp' !== substr($contents, 4, 4)) {
            throw new UnprocessableEntityHttpException('Un fichier M4A/AAC est requis.');
        }
        $input = ['-v', 'quiet', '-protocol_whitelist', 'file', '-f', 'mov', '-max_streams', '1', '-i', $upload->getPathname()];
        $codec = $this->output(['ffprobe', ...$input, '-show_entries', 'stream=codec_name', '-of', 'csv=p=0']);
        if ('aac' !== trim($codec)) {
            throw new UnprocessableEntityHttpException('Une unique piste AAC est requise.');
        }

        // Une seconde de surplus suffit à distinguer un message trop long. Le filtre
        // compte les échantillons indépendamment des timestamps déclarés dans le fichier.
        $pcm = $this->output(['ffmpeg', '-nostdin', '-xerror', ...$input, '-map', '0:a:0', '-af', 'aresample='.self::SAMPLE_RATE.',atrim=end_sample='.(self::MAX_SAMPLES + self::SAMPLE_RATE), '-ac', '1', '-f', 's16le', 'pipe:1']);
        $size = \strlen($pcm);
        if (0 === $size || $size > 2 * self::MAX_SAMPLES) {
            throw new UnprocessableEntityHttpException('La durée du vocal doit être positive et au plus de 60 secondes.');
        }
        $wav = 'RIFF'.pack('V', 36 + $size).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, self::SAMPLE_RATE, 2 * self::SAMPLE_RATE, 2, 16).'data'.pack('V', $size).$pcm;

        return new PreparedChatAttachment($wav, hash('sha256', $contents), (int) ceil($size * 1000 / (2 * self::SAMPLE_RATE)));
    }

    /** @param list<string> $command */
    private function output(array $command): string
    {
        $process = new Process($command, timeout: 10);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new UnprocessableEntityHttpException('Ce vocal ne peut pas être décodé.');
        }
        if (!$process->isSuccessful()) {
            throw new UnprocessableEntityHttpException('Ce vocal ne peut pas être décodé.');
        }

        return $process->getOutput();
    }
}
