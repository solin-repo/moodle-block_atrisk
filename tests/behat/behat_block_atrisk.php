<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Behat steps and page resolvers for block_atrisk.
 *
 * @package    block_atrisk
 * @category   test
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps and page resolvers for block_atrisk.
 *
 * @package    block_atrisk
 * @category   test
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_atrisk extends behat_base {
    /**
     * Resolve plugin page names for "I am on the "block_atrisk > X" page".
     *
     * Recognised page names: "Readiness export".
     *
     * @param string $page Page name.
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'readiness export':
                return new moodle_url('/blocks/atrisk/readiness.php');
            default:
                throw new Exception('Unrecognised block_atrisk page "' . $page . '."');
        }
    }

    /**
     * Resolve plugin instance pages for "I am on the "identifier" "block_atrisk > X" page".
     *
     * Recognised page types: "View all" (identifier: course shortname).
     *
     * @param string $type Page type.
     * @param string $identifier Identifier of the instance.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'view all':
                return new moodle_url('/blocks/atrisk/view.php', [
                    'courseid' => $this->get_course_id($identifier),
                ]);
            default:
                throw new Exception('Unrecognised block_atrisk page type "' . $type . '."');
        }
    }

    /**
     * Open a plugin page and check that Moodle refuses it with the given error text.
     *
     * An error page fails the next step's exception check, so this step asserts
     * the message and then returns to the site home page within one step.
     *
     * @Then /^opening the Early Warning page "(?P<path>[^"]*)" should fail with "(?P<text>[^"]*)"$/
     * @param string $path Local path of the page, e.g. /blocks/atrisk/readiness.php.
     * @param string $text Expected error text.
     */
    public function opening_page_should_fail_with(string $path, string $text): void {
        $this->assert_page_denied(new moodle_url($path), $text);
    }

    /**
     * Open the View all page of a course and check that Moodle refuses it.
     *
     * @Then /^opening the Early Warning "View all" page for "(?P<shortname>[^"]*)" should fail with "(?P<text>[^"]*)"$/
     * @param string $shortname Course shortname.
     * @param string $text Expected error text.
     */
    public function opening_view_all_should_fail_with(string $shortname, string $text): void {
        $this->assert_page_denied($this->resolve_page_instance_url('View all', $shortname), $text);
    }

    /**
     * Visit a URL, assert the error text is on the page, then leave the error page.
     *
     * @param moodle_url $url Page to open.
     * @param string $text Expected error text.
     */
    protected function assert_page_denied(moodle_url $url, string $text): void {
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
        $content = $this->getSession()->getPage()->getContent();
        $iserror = strpos($content, 'data-rel="fatalerror"') !== false
            || strpos($content, "data-rel='fatalerror'") !== false;
        $hastext = strpos($content, $text) !== false;
        $this->getSession()->visit($this->locate_path('/'));
        if (!$iserror || !$hastext) {
            throw new ExpectationException(
                'Expected an error page containing "' . $text . '" at ' . $url->out(false),
                $this->getSession()
            );
        }
    }

    /**
     * Undo a dismissal through dismiss.php with the browser session's sesskey.
     *
     * There is no undo link in the block itself; the endpoint is reached by
     * URL, so this step calls it the way a link would.
     *
     * @Given /^I undo the Early Warning dismissal of "(?P<username>[^"]*)" in course "(?P<shortname>[^"]*)"$/
     * @param string $username Username of the dismissed student.
     * @param string $shortname Course shortname.
     */
    public function i_undo_the_early_warning_dismissal(string $username, string $shortname): void {
        global $DB;
        $this->require_javascript();
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $sesskey = $this->getSession()->evaluateScript('return M.cfg.sesskey;');
        $url = new moodle_url('/blocks/atrisk/dismiss.php', [
            'courseid' => $this->get_course_id($shortname),
            'userid' => $userid,
            'action' => 'undo',
            'sesskey' => $sesskey,
        ]);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Submit the readiness export form in the background and check the JSON it returns.
     *
     * The page answers the POST with a file download, which the browser does
     * not display; the step posts the form through XHR instead and inspects
     * the response body.
     *
     * @Then /^the Early Warning readiness export should contain "(?P<text>(?:[^"]|\\")*)"$/
     * @param string $text Text expected in the JSON body.
     */
    public function the_readiness_export_should_contain(string $text): void {
        $body = $this->fetch_readiness_export();
        if (strpos($body, $text) === false) {
            throw new ExpectationException(
                'Readiness export does not contain "' . $text . '". Body starts: ' . substr($body, 0, 300),
                $this->getSession()
            );
        }
    }

    /**
     * Submit the readiness export form in the background and check a text is absent.
     *
     * @Then /^the Early Warning readiness export should not contain "(?P<text>(?:[^"]|\\")*)"$/
     * @param string $text Text that must not appear in the JSON body.
     */
    public function the_readiness_export_should_not_contain(string $text): void {
        $body = $this->fetch_readiness_export();
        if (strpos($body, $text) !== false) {
            throw new ExpectationException(
                'Readiness export unexpectedly contains "' . $text . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Post the readiness form via synchronous XHR and return the validated JSON body.
     *
     * @return string Response body.
     */
    protected function fetch_readiness_export(): string {
        $this->require_javascript();
        $script = <<<'JS'
            return (function() {
                var form = document.querySelector('form[action*="readiness.php"]');
                if (!form) {
                    return 'NOFORM';
                }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', form.action, false);
                xhr.send(new FormData(form));
                return xhr.status + ' ' + (xhr.getResponseHeader('Content-Type') || '') + '\n' + xhr.responseText;
            })();
JS;
        $result = (string) $this->getSession()->evaluateScript($script);
        [$head, $body] = array_pad(explode("\n", $result, 2), 2, '');
        if (strpos($head, '200 application/json') !== 0) {
            throw new ExpectationException(
                'Readiness export did not return JSON: ' . substr($result, 0, 300),
                $this->getSession()
            );
        }
        if (json_decode($body) === null) {
            throw new ExpectationException('Readiness export body is not valid JSON.', $this->getSession());
        }
        return $body;
    }
}
