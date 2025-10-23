<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;

use PhpPgAdmin\Database\QueryResult;


class ScriptActions extends ActionsBase
{

    /**
     * Private helper method to detect a valid $foo$ quote delimiter at
     * the start of the parameter dquote. UTF-8 compatible.
     * @return bool True if valid, false otherwise
     */
    private function valid_dolquote($dquote)
    {
        return (preg_match('/^[$][$]/u', $dquote) || preg_match('/^[$][_\p{L}][_\p{L}\p{N}]*[$]/u', $dquote));
    }

    /**
     * Handle COPY FROM data transfer for a query result
     * @param \PgSql\Connection $conn The PostgreSQL connection resource
     * @param QueryResult $result The wrapped query result
     * @param resource $fd File descriptor for reading COPY data
     * @param int &$lineno Line number counter (incremented as lines are read)
     * @return bool True if COPY completed successfully
     */
    private function handleCopyData($conn, $result, $fd, &$lineno)
    {
        if (!$result->isCopy) {
            return false;
        }

        while (!feof($fd)) {
            $copy = fgets($fd, 32768);
            $lineno++;
            pg_put_line($conn, $copy);
            if ($copy == "\\.\n" || $copy == "\\.\r\n") {
                pg_end_copy($conn);
                break;
            }
        }
        return true;
    }

    /**
     * Executes an SQL script as a series of SQL statements.  Returns
     * the result of the final step.  This is a lexer method
     * based on the REL7_4_STABLE src/bin/psql/mainloop.c lexer in
     * the PostgreSQL source code. UTF-8 and multibyte language aware.
     * @param string $name Entry in $_FILES to use
     * @param callable|null $callback (optional) Callback function to call with each query,
     * its result and line number.
     * @return bool True for general success, false on any failure.
     */
    function executeScript($name, $callback = null)
    {
        $pg = $this->postgres();

        // This whole function isn't very encapsulated, but hey...
        /**
         * @var \PgSql\Connection $conn
         */
        $conn = $pg->conn->_connectionID;
        if (!is_uploaded_file($_FILES[$name]['tmp_name']))
            return false;

        $fd = fopen($_FILES[$name]['tmp_name'], 'r');
        if (!$fd)
            return false;

        try {

            // Build up each SQL statement, they can be multiline
            $query_buf = null;
            $query_start = 0;
            $in_quote = 0;
            $in_xcomment = 0;
            $bslash_count = 0;
            $dol_quote = null;
            $paren_level = 0;
            $len = 0;
            $charpos = 0;  // character position (not byte position)
            $lineno = 0;

            // Loop over each line in the file
            while (!feof($fd)) {
                $line = fgets($fd);
                $lineno++;

                // Nothing left on line? Then ignore...
                if (trim($line) == '')
                    continue;

                $len = mb_strlen($line);
                $query_start = 0;

                /*
                 * Parse line, looking for command separators.
                 * Character position tracking (UTF-8 multibyte aware).
                 */

                for ($charpos = 0; $charpos < $len; $charpos++) {

                    /* was the previous character a backslash? */
                    if ($charpos > 0 && mb_substr($line, $charpos - 1, 1) == '\\')
                        $bslash_count++;
                    else
                        $bslash_count = 0;

                    /*
                     * It is important to place the in_* test routines before the
                     * in_* detection routines. i.e. we have to test if we are in
                     * a quote before testing for comments.
                     */

                    /* in quote? */
                    if ($in_quote !== 0) {
                        /*
                         * end of quote if matching non-backslashed character.
                         * backslashes don't count for double quotes, though.
                         */
                        if (
                            mb_substr($line, $charpos, 1) == $in_quote &&
                            ($bslash_count % 2 == 0 || $in_quote == '"')
                        )
                            $in_quote = 0;
                    } /* in or end of $foo$ type quote? */ else if ($dol_quote) {
                        if (mb_strpos(mb_substr($line, $charpos, null), $dol_quote, 0) === 0) {
                            $charpos++;
                            while (mb_substr($line, $charpos, 1) != '$')
                                $charpos++;
                            $dol_quote = null;
                        }
                    } /* start of extended comment? */ else if (mb_substr($line, $charpos, 2) == '/*') {
                        $in_xcomment++;
                        if ($in_xcomment == 1)
                            $charpos++;
                    } /* in or end of extended comment? */ else if ($in_xcomment) {
                        if (mb_substr($line, $charpos, 2) == '*/' && !--$in_xcomment)
                            $charpos++;
                    } /* start of quote? */ else if (mb_substr($line, $charpos, 1) == '\'' || mb_substr($line, $charpos, 1) == '"') {
                        $in_quote = mb_substr($line, $charpos, 1);
                    } /*
                      * start of $foo$ type quote?
                      */ else if (!$dol_quote && $this->valid_dolquote(mb_substr($line, $charpos, null))) {
                        $dol_end = mb_strpos(mb_substr($line, $charpos + 1, null), '$', 0);
                        $dol_quote = mb_substr($line, $charpos, $dol_end + 2);
                        $charpos++;
                        while (mb_substr($line, $charpos, 1) != '$') {
                            $charpos++;
                        }

                    } /* single-line comment? truncate line */ else if (mb_substr($line, $charpos, 2) == '--') {
                        $line = mb_substr($line, 0, $charpos); /* remove comment */
                        $len = mb_strlen($line);
                        break;
                    } /* count nested parentheses */ else if (mb_substr($line, $charpos, 1) == '(') {
                        $paren_level++;
                    } else if (mb_substr($line, $charpos, 1) == ')' && $paren_level > 0) {
                        $paren_level--;
                    } /* semicolon? then send query */ else if (mb_substr($line, $charpos, 1) == ';' && !$bslash_count && !$paren_level) {
                        $subline = mb_substr($line, $query_start, $charpos - $query_start);
                        /* is there anything else on the line? */
                        if (strspn($subline, " \t\n\r") != strlen($subline)) {
                            /*
                             * insert a cosmetic newline, if this is not the first
                             * line in the buffer
                             */
                            if (strlen($query_buf) > 0)
                                $query_buf .= "\n";
                            $query_buf .= $subline;
                        }
                        $query_buf .= ';';

                        /* is there anything in the query_buf? */
                        if (trim($query_buf)) {
                            // Execute the query using raw pg_*
                            $rs = @pg_query($conn, $query_buf);
                            $errorMsg = '';

                            if ($rs === false) {
                                $errorMsg = pg_last_error($conn);
                            }

                            // Wrap result and call callback
                            $wrappedResult = QueryResult::fromPgResult($rs, $errorMsg);
                            if ($callback !== null)
                                $callback($query_buf, $wrappedResult, $lineno);

                            // Check for COPY request
                            if ($wrappedResult->isCopy) {
                                $this->handleCopyData($conn, $wrappedResult, $fd, $lineno);
                            }
                        }

                        $query_buf = null;
                        $query_start = $charpos + 1;
                    }

                    /*
                     * keyword or identifier?
                     * We grab the whole string so that we don't
                     * mistakenly see $foo$ inside an identifier as the start
                     * of a dollar quote.
                     */ else if (preg_match('/^[_\\p{L}]$/u', mb_substr($line, $charpos, 1))) {
                        $sub = mb_substr($line, $charpos, 1);
                        while (preg_match('/^[\\$_\\p{L}\\p{N}]$/u', $sub)) {
                            /* keep going while we still have identifier chars */
                            $charpos++;
                            if ($charpos < $len)
                                $sub = mb_substr($line, $charpos, 1);
                            else
                                break;
                        }
                        // Since we're now over the next character to be examined,
                        // it is necessary to move back one space.
                        $charpos--;
                    }
                } // end for

                /* Put the rest of the line in the query buffer. */
                $subline = mb_substr($line, $query_start, null);
                if ($in_quote || $dol_quote || strspn($subline, " \t\n\r") != mb_strlen($subline)) {
                    if (mb_strlen($query_buf ?? '') > 0)
                        $query_buf .= "\n";
                    $query_buf .= $subline;
                }

                $line = null;

            } // end while

            /*
             * Process query at the end of file without a semicolon, so long as
             * it's non-empty.
             */
            if (mb_strlen($query_buf ?? '') > 0 && strspn($query_buf, " \t\n\r") != mb_strlen($query_buf)) {
                // Execute the query using raw pg_*
                $rs = @pg_query($conn, $query_buf);
                $errorMsg = '';

                if ($rs === false) {
                    $errorMsg = pg_last_error($conn);
                }

                // Wrap result and call callback
                $wrappedResult = QueryResult::fromPgResult($rs, $errorMsg);
                if ($callback !== null)
                    $callback($query_buf, $wrappedResult, $lineno);

                // Check for COPY request
                if ($wrappedResult->isCopy) {
                    $this->handleCopyData($conn, $wrappedResult, $fd, $lineno);
                }
            }

            return true;
        } finally {
            fclose($fd);
        }
    }

}