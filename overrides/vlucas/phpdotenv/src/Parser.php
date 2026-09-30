<?php

namespace Dotenv;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Regex\Regex;

class Parser
{
    const INITIAL_STATE = 0;
    const UNQUOTED_STATE = 1;
    const QUOTED_STATE = 2;
    const ESCAPE_STATE = 3;
    const WHITESPACE_STATE = 4;
    const COMMENT_STATE = 5;

    /**
     * Parse the given environment variable entry into a name and value.
     *
     * @param string $entry
     *
     * @throws \Dotenv\Exception\InvalidFileException
     *
     * @return array
     */
    public static function parse($entry)
    {
        list($name, $value) = self::splitStringIntoParts($entry);

        return [self::parseName($name), self::parseValue($value)];
    }

    /**
     * Split the compound string into parts.
     *
     * @param string $line
     *
     * @throws \Dotenv\Exception\InvalidFileException
     *
     * @return array
     */
    private static function splitStringIntoParts($line)
    {
        $name = $line;
        $value = null;

        if (strpos($line, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $line, 2));
        }

        if ($name === '') {
            throw new InvalidFileException(
                self::getErrorMessage('an unexpected equals', $line)
            );
        }

        return [$name, $value];
    }

    /**
     * Strips quotes and the optional leading "export " from the variable name.
     *
     * @param string $name
     *
     * @throws \Dotenv\Exception\InvalidFileException
     *
     * @return string
     */
    private static function parseName($name)
    {
        $name = trim(str_replace(['export ', '\'', '"'], '', $name));

        if (!self::isValidName($name)) {
            throw new InvalidFileException(
                self::getErrorMessage('an invalid name', $name)
            );
        }

        return $name;
    }

    /**
     * Is the given variable name valid?
     *
     * @param string $name
     *
     * @return bool
     */
    private static function isValidName($name)
    {
        return Regex::match('~\A[a-zA-Z0-9_.]+\z~', $name)->success()->getOrElse(0) === 1;
    }

    /**
     * Strips quotes and comments from the environment variable value.
     *
     * @param string|null $value
     *
     * @throws \Dotenv\Exception\InvalidFileException
     *
     * @return string|null
     */
    private static function parseValue($value)
    {
        // FreeScout's parsing from its patched phpdotenv 2, kept so existing
        // .env files read the same: a quoted value runs to the last quote
        // (https://github.com/freescout-helpdesk/freescout/issues/2822),
        // backslashes other than \" and \\ are kept, and in an unquoted
        // value only " #" starts a comment.
        if ($value === null || trim($value) === '') {
            return $value;
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

        // Unquoted values cannot contain whitespace
        if (preg_match('/\s+/', $value) > 0) {
            // Check if value is a comment (usually triggered when empty value with comment)
            if (preg_match('/^#/', $value) > 0) {
                return '';
            }
            throw new InvalidFileException('Dotenv values containing spaces must be surrounded by quotes.');
        }

        return $value;
    }

    /**
     * Generate a friendly error message.
     *
     * @param string $cause
     * @param string $subject
     *
     * @return string
     */
    private static function getErrorMessage($cause, $subject)
    {
        return sprintf(
            'Failed to parse dotenv file due to %s. Failed at [%s].',
            $cause,
            strtok($subject, "\n")
        );
    }
}
