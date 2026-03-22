<?php

declare(strict_types=1);

namespace Ramic\Rsync\Transport;

use Ramic\Rsync\Algorithm\BlockChecksums;
use Ramic\Rsync\Algorithm\CopyInstruction;
use Ramic\Rsync\Algorithm\DeltaInstruction;
use Ramic\Rsync\Algorithm\LiteralInstruction;

/**
 * HTTP client that communicates with the syncgate/receiver.php endpoint.
 *
 * All requests are POST with a JSON body. The secret key is embedded in
 * every payload so no custom headers are required (shared hosting compatible).
 */
class HttpTransport
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $key,
        private readonly int    $timeout = 120,
    ) {}

    /**
     * List all files managed by the remote receiver.
     *
     * @return array<string, array{size: int, mtime: int, type: string}>
     */
    public function listFiles(): array
    {
        $response = $this->request(['action' => 'list']);
        return $response['files'] ?? [];
    }

    /**
     * Fetch block checksums for a remote file.
     */
    public function getBlockChecksums(string $relPath, int $blockSize): BlockChecksums
    {
        $response = $this->request([
            'action'     => 'checksums',
            'path'       => $relPath,
            'block_size' => $blockSize,
        ]);

        return BlockChecksums::fromArray($response['checksums'] ?? []);
    }

    /**
     * Send a delta to the remote; the receiver applies it and writes the file.
     *
     * @param DeltaInstruction[] $instructions
     */
    public function applyDelta(
        string $relPath,
        array  $instructions,
        int    $blockSize,
        ?int   $mtime = null,
    ): void {
        $payload = [
            'action'       => 'apply_delta',
            'path'         => $relPath,
            'block_size'   => $blockSize,
            'instructions' => $this->encodeInstructions($instructions),
        ];
        if ($mtime !== null) {
            $payload['mtime'] = $mtime;
        }
        $this->request($payload);
    }

    /**
     * Upload a complete file (used for new files where no delta base exists).
     */
    public function sendFile(string $relPath, string $content, ?int $mtime = null): void
    {
        $payload = [
            'action'  => 'send_file',
            'path'    => $relPath,
            'content' => base64_encode($content),
        ];
        if ($mtime !== null) {
            $payload['mtime'] = $mtime;
        }
        $this->request($payload);
    }

    /**
     * Push a new version of receiver.php to the remote endpoint.
     * The remote atomically replaces itself with the new content.
     */
    public function updateReceiver(string $localReceiverPath): void
    {
        $content = file_get_contents($localReceiverPath);
        if ($content === false) {
            throw new TransportException("Cannot read local receiver: $localReceiverPath");
        }
        $this->request([
            'action'  => 'update_receiver',
            'content' => base64_encode($content),
        ]);
    }

    /**
     * Delete a file or empty directory on the remote.
     */
    public function deleteFile(string $relPath): void
    {
        $this->request(['action' => 'delete', 'path' => $relPath]);
    }

    /**
     * Create a directory on the remote (recursive).
     */
    public function makeDir(string $relPath): void
    {
        $this->request(['action' => 'mkdir', 'path' => $relPath]);
    }

    // -------------------------------------------------------------------------

    /**
     * @param DeltaInstruction[] $instructions
     * @return array<int, array{type: string, block?: int, data?: string}>
     */
    private function encodeInstructions(array $instructions): array
    {
        $encoded = [];
        foreach ($instructions as $instr) {
            if ($instr instanceof CopyInstruction) {
                $encoded[] = ['type' => 'copy', 'block' => $instr->blockIndex];
            } elseif ($instr instanceof LiteralInstruction) {
                $encoded[] = ['type' => 'literal', 'data' => base64_encode($instr->data)];
            }
        }
        return $encoded;
    }

    /**
     * Perform a POST request to the endpoint and return the decoded response.
     *
     * @throws TransportException on network error, non-2xx status, or server error
     */
    private function request(array $data): array
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('The cURL extension is required for remote sync.');
        }

        $data['key'] = $this->key;
        $json        = json_encode($data);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new TransportException("Network error: $curlErr");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new TransportException("Invalid JSON response (HTTP $httpCode): " . substr($response, 0, 200));
        }

        if (!($decoded['ok'] ?? false)) {
            throw new TransportException($decoded['error'] ?? "Remote error (HTTP $httpCode)");
        }

        return $decoded;
    }
}
