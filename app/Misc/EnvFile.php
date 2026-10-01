<?php

namespace App\Misc;

/**
 * Writes the .env file in the standard dotenv syntax.
 *
 * FreeScout wrote and read .env with its own rules (a patched phpdotenv 2).
 * Values are now written so that those rules and standard phpdotenv read
 * them the same: unquoted when that is unambiguous, otherwise in double
 * quotes with \ and " escaped. ${NAME} references stay references, as
 * before. standardize() rewrites an existing file that way.
 */
class EnvFile
{
    /**
     * A value as it is written to .env.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function formatValue($value)
    {
        $value = str_replace(["\r", "\n"], '', (string) $value);

        if (preg_match('#^[A-Za-z0-9_.:/@+=,-]*$#', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Set a variable in an .env file: replaces the line(s) defining exactly
     * this key, or adds a line.
     *
     * @param  string  $path
     * @param  string  $key
     * @param  mixed  $value
     */
    public static function setVar($path, $key, $value)
    {
        $contents = file_exists($path) ? file_get_contents($path) : '';
        $line = $key.'='.self::formatValue($value);
        $pattern = '/^'.preg_quote($key, '/').'[ \t]*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $contents = preg_replace_callback($pattern, function () use ($line) {
                return $line;
            }, $contents);
        } else {
            $contents = rtrim($contents, "\r\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }

    /**
     * Rewrite an .env file written with FreeScout's rules in the standard
     * syntax, keeping a copy of the original. Values keep their meaning;
     * comments after unquoted values are dropped. Lines that can't be read
     * are left as they are.
     *
     * @param  string  $path
     * @return int Number of lines rewritten.
     */
    public static function standardize($path)
    {
        if (!is_file($path)) {
            return 0;
        }
        $contents = file_get_contents($path);
        $lines = explode("\n", $contents);
        $changed = 0;

        foreach ($lines as $i => $line) {
            $eol = str_ends_with($line, "\r") ? "\r" : '';
            $line = rtrim($line, "\r");
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$name, $raw] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^(export\s+)?[A-Za-z_][A-Za-z0-9_.]*$/', $name)) {
                continue;
            }

            $value = self::parseLegacyValue($raw);
            if ($value === null) {
                continue;
            }

            $standard = $name.'='.self::formatValue($value);
            if ($standard !== $line) {
                $lines[$i] = $standard.$eol;
                $changed++;
            }
        }

        if ($changed) {
            copy($path, $path.'.backup-'.date('YmdHis'));
            file_put_contents($path, implode("\n", $lines));
        }

        return $changed;
    }

    /**
     * A value as FreeScout's patched phpdotenv 2 read it, ${NAME} references
     * unresolved: a quoted value runs to the last quote, only \" and \\ are
     * unescaped, and in an unquoted value " #" starts a comment.
     *
     * @param  string  $value  The trimmed text after "=".
     * @return string|null null for an unquoted value with spaces (an error).
     */
    public static function parseLegacyValue($value)
    {
        if (trim($value) === '') {
            return '';
        }

        if ($value[0] === '"' || $value[0] === '\'') {
            $quote = $value[0];
            if (preg_match(sprintf('#\\\\%1$s$#mx', $quote), $value)) {
                $value = rtrim($value, $quote);
            }
            $value = preg_replace(sprintf('/(.*[^\\\\])%1$s[^%1$s]*/mx', $quote), '$1', $value);
            $value = substr($value, 1);

            $value = str_replace("\\$quote", $quote, $value);

            return str_replace('\\\\', '\\', $value);
        }

        $parts = explode(' #', $value, 2);
        $value = trim($parts[0]);

        if (preg_match('/\s+/', $value) > 0) {
            if (preg_match('/^#/', $value) > 0) {
                return '';
            }

            return null;
        }

        return $value;
    }
}
