<?php
namespace SHUTDOWN\Util;

/**
 * Extremely small PDF text extractor tailored for simple text bulletins.
 * It only understands plain text streams using Tj/TJ operators, which is
 * sufficient for the utility outage notices that LESCO publishes.
 */
class PdfTextExtractor
{
    public static function extractText(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $streams = self::extractStreams($binary);
        $lines = [];

        foreach ($streams as $raw) {
            $decoded = self::decodeStream($raw);
            if ($decoded === '') {
                continue;
            }
            foreach (self::extractTextChunks($decoded) as $chunk) {
                $chunk = self::normalizeEncoding($chunk);
                $chunk = str_replace(["\r"], "", $chunk);
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $lines[] = $chunk;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private static function extractStreams(string $binary): array
    {
        $streams = [];
        if (!preg_match_all('/stream\r?\n(.*?)endstream/s', $binary, $matches)) {
            return $streams;
        }
        foreach ($matches[1] as $match) {
            $streams[] = $match;
        }
        return $streams;
    }

    private static function decodeStream(string $stream): string
    {
        $stream = ltrim($stream, "\r\n");
        if ($stream === '') {
            return '';
        }
        $decoded = self::tryDecode($stream);
        if ($decoded !== null) {
            return $decoded;
        }
        return $stream;
    }

    private static function tryDecode(string $stream): ?string
    {
        $candidates = [
            'gzuncompress',
            'gzdecode',
            'gzinflate',
        ];
        foreach ($candidates as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            $result = @$fn($stream);
            if (is_string($result)) {
                return $result;
            }
        }
        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function extractTextChunks(string $content): array
    {
        $chunks = [];

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $chunks[] = self::decodeArrayChunk($match[1]);
            }
            $content = preg_replace('/\[(.*?)\]\s*TJ/s', '', $content) ?? $content;
        }

        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*T[Jj]/s', $content, $hexMatches, PREG_SET_ORDER)) {
            foreach ($hexMatches as $match) {
                $chunks[] = self::decodeHexString($match[1]);
            }
            $content = preg_replace('/<([0-9A-Fa-f\s]+)>\s*T[Jj]/s', '', $content) ?? $content;
        }

        if (preg_match_all('/(\((?:\\\\.|[^\\\\)])*\))\s*T[Jj]/s', $content, $literalMatches, PREG_SET_ORDER)) {
            foreach ($literalMatches as $match) {
                $chunks[] = self::decodeLiteralString($match[1]);
            }
        }

        return array_filter($chunks, static fn ($chunk) => $chunk !== '');
    }

    private static function decodeArrayChunk(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        $out = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $payload, $matches)) {
            foreach ($matches[0] as $literal) {
                $out[] = self::decodeLiteralString($literal);
            }
        }
        if (preg_match_all('/<([0-9A-Fa-f\s]+)>/s', $payload, $hexMatches)) {
            foreach ($hexMatches[1] as $hex) {
                $out[] = self::decodeHexString($hex);
            }
        }
        return trim(implode('', $out));
    }

    private static function decodeLiteralString(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if ($token[0] === '(') {
            $token = substr($token, 1, -1);
        }
        $out = '';
        $length = strlen($token);
        for ($i = 0; $i < $length; $i++) {
            $char = $token[$i];
            if ($char !== '\\') {
                $out .= $char;
                continue;
            }
            $i++;
            if ($i >= $length) {
                break;
            }
            $next = $token[$i];
            switch ($next) {
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'b': $out .= "\b"; break;
                case 'f': $out .= "\f"; break;
                case '(':
                case ')':
                case '\\':
                    $out .= $next;
                    break;
                default:
                    if (ctype_digit($next)) {
                        $oct = $next;
                        for ($j = 0; $j < 2 && $i + 1 < $length; $j++) {
                            if (!ctype_digit($token[$i + 1])) {
                                break;
                            }
                            $i++;
                            $oct .= $token[$i];
                        }
                        $out .= chr(octdec($oct));
                    } else {
                        $out .= $next;
                    }
            }
        }
        return $out;
    }

    private static function decodeHexString(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $binary = pack('H*', $hex);
        return $binary === false ? '' : $binary;
    }

    private static function normalizeEncoding(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (str_starts_with($text, "\xFE\xFF")) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-16BE');
            if (is_string($converted)) {
                return $converted;
            }
        }
        return $text;
    }
}
