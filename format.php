<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Canvas IMSQTI XML question importer.
 *
 * @package    qformat_canvas
 * @copyright  2014 Jean-Michel Vedrine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/xmlize.php');

class qformat_canvas extends qformat_based_on_xml {
    public function provide_import() {
        return true;
    }

    public function mime_type() {
        return 'application/xml';
    }

    /**
     * For now this is just a wrapper for cleaninput.
     * @param string text text to parse and recode
     * @return array with keys text, format, itemid.
     */
    public function cleaned_text_field($text) {
        return array('text' => $this->cleaninput($text), 'format' => FORMAT_HTML);
    }
    /**
     * Parse the xml document into an array of questions
     * this *could* burn memory - but it won't happen that much
     * so fingers crossed!
     * @param $lines array of lines from the input file.
     * @return array (of objects) questions objects.
     */
    public function readquestions($lines) {
        question_bank::get_qtype('multianswer'); // Ensure the multianswer code is loaded.
        $text = implode($lines, ' ');
        unset($lines);
        // This converts xml to big nasty data structure,
        // the 0 means keep white space as it is.
        try {
            $xml = xmlize($text, 0, 'UTF-8', true);
        } catch (xml_format_exception $e) {
            $this->error($e->getMessage(), '');
            return false;
        }

        $questions = array();
        // First step : we are only interested in the <item> tags.
        $questionsdata = $this->getpath($xml,
                array('questestinterop', '#', 'assessment', 0, '#', 'section', 0, '#', 'item'),
                array(), false);
        // Each <item> tag contains data related to a single question.
        foreach ($questionsdata as $questiondata) {
            // Second step : parse each question data into the intermediate
            // rawquestion structure array.
            // Warning : rawquestions are not Moodle questions.
            $rawquestion = $this->create_raw_question($questiondata);
            // Third step : convert a rawquestion into a Moodle question.
            switch($rawquestion->qtype) {
                case 'multiple_choice_question':
                    $this->process_mc($rawquestion, $questions);
                    break;
                case 'multiple_answers_question':
                    $this->process_ma($rawquestion, $questions);
                    break;
                case 'true_false_question':
                    $this->process_tf($rawquestion, $questions);
                    break;
                case 'short_answer_question':
                    $this->process_sa($rawquestion, $questions);
                    break;
                case 'essay_question':
                case 'file_upload_question':
                    $this->process_essay($rawquestion, $questions);
                    break;
                case 'matching_question':
                    $this->process_matching($rawquestion, $questions);
                    break;
                case 'multiple_dropdowns_question':
                case 'fill_in_multiple_blanks_question':
                    $this->process_multiple($rawquestion, $questions, $rawquestion->qtype);
                    break;
                case 'numerical_question':
                    $this->process_num($rawquestion, $questions);
                    break;
                case 'text_only_question':
                    $this->process_description($rawquestion, $questions);
                    break;
                case 'calculated_question':
                    $this->process_calculated($rawquestion, $questions);
                default:
                    $this->error(get_string('unknownorunhandledtype', 'question', $rawquestion->qtype));
                    break;
            }
        }
        return $questions;
    }

    /**
     * Creates a cleaner object to deal with for processing into Moodle.
     * The object returned is NOT a moodle question object.
     * @param array $quest XML <item> question  data
     * @return object rawquestion
     */
    public function create_raw_question($quest) {
        $rawquestion = new stdClass();
        $meta = $this->getpath($quest,
                array('#', 'itemmetadata', 0, '#', 'qtimetadata', 0, '#', 'qtimetadatafield'),
                false, false);
        $rawquestion->qtype = $this->find_metadata($meta, 'question_type');
        $rawquestion->defaultmark = $this->find_metadata($meta, 'points_possible', 1);
        $rawquestion->title = $this->cleaninput($this->getpath($quest,
                array('@', 'title'),
                '', true));
        $rawquestion->id = $this->getpath($quest,
                array('@', 'ident'),
                '', true);

        $presentation = new stdClass();
        $presentation->blocks = $this->getpath($quest,
                array('#', 'presentation'),
                array(), false);
        foreach ($presentation->blocks as $pblock) {
            $block = new stdClass();
            $this->process_block($pblock, $block);
            $rawquestion->question = $block;
            switch($rawquestion->qtype) {
                case 'multiple_choice_question':
                case 'multiple_answers_question':
                case 'true_false_question':
                    $rawchoices = $this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_choice', 0, '#', 'response_label'),
                            array(), false);
                    $choices = array();
                    $this->process_choices($rawchoices, $choices);
                    $rawquestion->choices = $choices;
                    break;
                case 'fill_in_multiple_blanks_question':
                case 'multiple_dropdowns_question':
                    $parts = $this->getpath($pblock,
                            array('#', 'response_lid'),
                            array(), false);
                    $rawparts = array();
                    foreach ($parts as $part) {
                        $block = new stdClass();
                        $this->process_block($part, $block);
                        $rawchoices = $this->getpath($part,
                                array( '#', 'render_choice', 0, '#', 'response_label'),
                                array(), false);
                        $choices = array();
                        $this->process_choices($rawchoices, $choices);
                        $rawparts[$block->text] = $choices;
                    }
                    $rawquestion->choices = $rawparts;
                    break;
                case 'matching_question':
                    $rawsubquestions = $this->getpath($pblock,
                            array('#', 'response_lid'),
                            array(), false);
                    $this->process_subquestions($rawsubquestions, $subquestions);
                    $rawquestion->subquestions = $subquestions;
                    break;
                case 'short_answer_question':
                    // TODO process response_str tag if necessary.
                    break;
                case 'numerical_question':
                    break;
                case 'file_upload_question':
                case 'essay_question':
                case 'text_only_question':
                default:
                    // Nothing to do here.
                    break;
            }
        }
        if ($rawquestion->qtype != 'text_only_question'
                && $rawquestion->qtype != 'essay_question'
                && $rawquestion->qtype != 'file_upload_question') {
            // Determine response processing.
            $resprocessing = $this->getpath($quest,
                    array('#', 'resprocessing'),
                    array(), false);
            $respconditions = $this->getpath($resprocessing[0],
                    array('#', 'respcondition'),
                    array(), false);
            $rawquestion->maxmark = (float)$this->getpath($resprocessing[0],
                    array('#', 'outcomes', 0, '#', 'decvar', 0, '@', 'maxvalue'),
                    array(), false);
            $rawquestion->minmark = (float)$this->getpath($resprocessing[0],
                    array('#', 'outcomes', 0, '#', 'decvar', 0, '@', 'minvalue'),
                    array(), false);
            $rawquestion->varname = (float)$this->getpath($resprocessing[0],
                    array('#', 'outcomes', 0, '#', 'decvar', 0, '@', 'varname'),
                    array(), false);
            $responses = array();
            if ($rawquestion->qtype == 'matching_question') {
                $this->process_matching_responses($respconditions, $responses);
            } else if ($rawquestion->qtype == 'numerical_question') {
                $this->process_num_responses($respconditions, $responses);
            } else {
                $this->process_responses($respconditions, $responses);
            }
            $rawquestion->responses = $responses;
        }

        $feedbackset = $this->getpath($quest,
                array('#', 'itemfeedback'),
                array(), false);

        $feedbacks = array();
        $this->process_feedback($feedbackset, $feedbacks);
        $rawquestion->feedback = $feedbacks;
        return $rawquestion;
    }

    /**
     * Helper function to process an XML block into an object.
     * Can call himself recursively if necessary to parse this branch of the XML tree.
     * @param array $curblock XML block to parse
     * @return object $block parsed
     */
    public function process_block($curblock, $block) {

        // Foe now all blocks are of type Block.
        $curtype = 'Block';
        switch($curtype) {
            case 'Block':
                if ($this->getpath($curblock,
                        array('#', 'material', 0, '#', 'mattext'),
                        false, false)) {
                    $block->text = $this->getpath($curblock,
                            array('#', 'material', 0, '#', 'mattext', 0, '#'),
                            '', true);
                } else if ($this->getpath($curblock,
                        array('#', 'material', 0, '#', 'mat_extension', 0, '#', 'mat_formattedtext'),
                        false, false)) {
                    $block->text = $this->getpath($curblock,
                            array('#', 'material', 0, '#', 'mat_extension', 0, '#', 'mat_formattedtext', 0, '#'),
                            '', true);
                } else if ($this->getpath($curblock,
                        array('#', 'response_label'),
                        false, false)) {
                    // This is a response label block.
                    $subblocks = $this->getpath($curblock,
                            array('#', 'response_label', 0),
                            array(), false);
                    if (!isset($block->ident)) {
                        if ($this->getpath($subblocks,
                                array('@', 'ident'), '', true)) {
                            $block->ident = $this->getpath($subblocks,
                                array('@', 'ident'), '', true);
                        }
                    }
                    foreach ($this->getpath($subblocks,
                            array('#', 'flow_mat'), array(), false) as $subblock) {
                        $this->process_block($subblock, $block);
                    }
                } else {
                    if ($this->getpath($curblock,
                                array('#', 'flow_mat'), false, false)
                            || $this->getpath($curblock,
                                array('#', 'flow'), false, false)) {
                        if ($this->getpath($curblock,
                                array('#', 'flow_mat'), false, false)) {
                            $subblocks = $this->getpath($curblock,
                                    array('#', 'flow_mat'), array(), false);
                        } else if ($this->getpath($curblock,
                                array('#', 'flow'), false, false)) {
                            $subblocks = $this->getpath($curblock,
                                    array('#', 'flow'), array(), false);
                        }
                        foreach ($subblocks as $sblock) {
                            // This will recursively grab the sub blocks which should be of one of the other types.
                            $this->process_block($sblock, $block);
                        }
                    }
                }
                break;
        }
        return $block;
    }

    protected function find_metadata($data, $label, $default = '') {
        $metadatas = $this->getpath($data,
                array('#', 'itemmetadata', 0, '#', 'qtimetadata', 0, '#', 'qtimetadatafield'),
                false, false);

        foreach ($data as $metadata) {
            if ($this->getpath($metadata,
                    array('#', 'fieldlabel', 0, '#'),
                    false, false) == $label) {
                return $this->getpath($metadata,
                        array('#', 'fieldentry', 0, '#'),
                        '', true);
            }

        }
        return $default;
    }

    /**
     * Preprocess XML blocks containing data for questions' choices.
     * Called by {@link create_raw_question()}
     * for matching, multichoice and fill in the blank questions.
     * @param array $bbchoices XML block to parse
     * @param array $choices array of choices suitable for a rawquestion.
     */
    protected function process_choices($bbchoices, &$choices) {
        foreach ($bbchoices as $choice) {
            $curchoice = new stdClass();
            if ($this->getpath($choice,
                    array('@', 'ident'), '', true)) {
                $curchoice->ident = $this->getpath($choice,
                        array('@', 'ident'), '', true);
            } else { // For multiple answers.
                $curchoice->ident = $this->getpath($choice,
                         array('#', 'response_label', 0), array(), false);
            }

            if ($this->getpath($choice,
                    array('#', 'flow_mat', 0), false, false)) {
                $curblock = $this->getpath($choice,
                    array('#', 'flow_mat', 0), false, false);
                $this->process_block($curblock, $curchoice);
            } else {
                $this->process_block($choice, $curchoice);
            }
            $choices[$curchoice->ident] = $curchoice;
        }
    }

    /**
     * Preprocess XML blocks containing data for matching questions subquestions.
     * Called by {@link create_raw_question()}
     * for matching questions.
     * @param array $awsubqestions XML block to parse
     * @param array $subquestions array of subquestions suitable for a matching rawquestion.
     */
    protected function process_subquestions($rawsubqestions, &$subqestions) {
        foreach ($rawsubqestions as $rawsubq) {
            $cursubq = new stdClass();
            if ($this->getpath($rawsubq,
                    array('@', 'ident'), '', true)) {
                $cursubq->ident = $this->getpath($rawsubq,
                        array('@', 'ident'), '', true);
            }
            $this->process_block($rawsubq, $cursubq);
            $rawchoices = $this->getpath($rawsubq,
                        array('#', 'render_choice', 0, '#', 'response_label'), array(), false);
            $choices = array();
            $this->process_choices($rawchoices, $choices);

            $cursubq->choices = $choices;

            $subqestions[] = $cursubq;
        }
    }

    /**
     * Preprocess XML blocks containing data for subanswers
     * Called by {@link create_raw_question()}
     * for matching questions only.
     * @param array $bbresponses XML block to parse
     * @param array $responses array of responses suitable for a matching rawquestion.
     */
    protected function process_matching_responses($bbresponses, &$responses) {
        foreach ($bbresponses as $bbresponse) {
            $response = new stdClass;
            if ($this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'varequal'), false, false)) {
                $response->correct = $this->getpath($bbresponse,
                        array('#', 'conditionvar', 0, '#', 'varequal', 0, '#'), '', true);
                $response->ident = $this->getpath($bbresponse,
                        array('#', 'conditionvar', 0, '#', 'varequal', 0, '@', 'respident'), '', true);
            }

            $responses[] = $response;
        }
    }

    /**
     * Preprocess XML blocks containing data for responses processing.
     * Called by {@link create_raw_question()}
     * for all questions types except matching and numerical.
     * @param array $bbresponses XML block to parse
     * @param array $responses array of responses suitable for a rawquestion.
     */
    protected function process_responses($bbresponses, &$responses) {
        foreach ($bbresponses as $bbresponse) {
            $response = new stdClass();
            if ($this->getpath($bbresponse,
                    array('@', 'title'), '', true)) {
                $response->title = $this->getpath($bbresponse,
                        array('@', 'title'), '', true);
            } else {
                $response->title = $this->getpath($bbresponse,
                        array('#', 'displayfeedback', 0, '@', 'linkrefid'), '', true);
            }
            $response->ident = array();
            if ($this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'other', 0, '#'), false, false)) {
                $response->ident[0] = $this->getpath($bbresponse,
                        array('#', 'conditionvar', 0, '#', 'other', 0, '#'), array(), false);
            } else if ($this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'and'), false, false)) {
                $responseset = $this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'and', 0, '#'), array(), false);
                foreach ($responseset as $rsid => $rs) {
                    if ($rsid == 'varequal') {
                        if (is_array($rs)) {
                            foreach ($rs as $resp) {
                                $response->ident[] = $this->getpath($resp, array('#'), '', true);
                            }
                        }
                    }
                    if (!isset($response->respident) and $this->getpath($rs, array('@', 'respident'), false, false)) {
                        $response->respident = $this->getpath($rs,
                                array('@', 'respident'), '', true);
                    }
                }
            } else {
                $responseset = $this->getpath($bbresponse,
                        array('#', 'conditionvar'), array(), false);
                foreach ($responseset as $rs) {
                    $resp = $this->getpath($rs, array('#', 'varequal'), array(), false);
                    if (is_array($resp)) {
                        foreach ($resp as $ans) {
                            $response->ident[] = $this->getpath($ans, array('#'), '', true);
                            if ($this->getpath($ans, array('@', 'respident'), false, false)) {
                                $response->respident[] = $this->getpath($ans,
                                        array('@', 'respident'), '', true);
                            } else {
                                $response->respident[] = '';
                            }
                        }
                    } else {
                        $response->ident[] = $this->getpath($rs, array('#', 'varequal', 0, '#'), '', true);
                    }
                    if ($this->getpath($rs, array('#', 'varequal', 0, '@', 'respident'), false, false)) {
                        $response->respident = $this->getpath($rs,
                                array('#', 'varequal', 0, '@', 'respident'), '', true);
                    }
                }

            }
            if ($this->getpath($bbresponse,
                    array('#', 'displayfeedback', 0, '@', 'linkrefid'), false, false)) {
                $response->feedback = $this->getpath($bbresponse,
                        array('#', 'displayfeedback', 0, '@', 'linkrefid'), '', true);
            }

            // Determine what mark to give to this response.
            if ($this->getpath($bbresponse,
                    array('#', 'setvar', 0, '#'), false, false)) {
                $response->mark = (float)$this->getpath($bbresponse,
                        array('#', 'setvar', 0, '#'), '', true);
                if ($response->mark > 0.0) {
                    $response->title = 'correct';
                } else {
                    $response->title = 'incorrect';
                }
            }
            $responses[] = $response;
        }
    }

    /**
     * Preprocess XML blocks containing data for responses processing.
     * Called by {@link create_raw_question()}
     * for numerical questions type.
     * @param array $bbresponses XML block to parse
     * @param array $responses array of responses suitable for a rawquestion.
     */
    protected function process_num_responses($bbresponses, &$responses) {
        foreach ($bbresponses as $bbresponse) {
            $response = new stdClass();
            if ($this->getpath($bbresponse,
                    array('@', 'title'), '', true)) {
                $response->title = $this->getpath($bbresponse,
                        array('@', 'title'), '', true);
            } else {
                $response->title = $this->getpath($bbresponse,
                        array('#', 'displayfeedback', 0, '@', 'linkrefid'), '', true);
            }

            $response->value = array();
            $response->minvalue = array();
            $response->maxvalue = array();
            if ($this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'other', 0, '#'), false, false)) {
                $response->ident = $this->getpath($bbresponse,
                        array('#', 'conditionvar', 0, '#', 'other', 0, '#'), '', true);
            } else if ($this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'or'), false, false)) {
                $responseset = $this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'or', 0, '#'), array(), false);
                foreach ($responseset as $rsid => $rs) {
                    if ($rsid === 'varequal') {
                        if (is_array($rs)) {
                            foreach ($rs as $resp) {
                                $response->value[] = $this->getpath($resp, array('#'), '', true);
                            }
                        }
                    } else if ($rsid === 'and') {
                        $limits = $this->getpath($rs,
                                array(0, '#'), array(), false);
                        foreach ($limits as $limitop => $limit) {
                            if ($limitop === 'varlte') {
                                 $response->maxvalue[] = $this->getpath($limit, array(0, '#'), '', true);
                            } else if ($limitop === 'vargte') {
                                 $response->minvalue[] = $this->getpath($limit, array(0, '#'), '', true);
                            }
                        }
                    }
                }
            } else {
                $responseset = $this->getpath($bbresponse,
                        array('#', 'conditionvar', 0, '#'), array(), false);
                foreach ($responseset as $rsid => $rs) {
                    if ($rsid === 'varlte') {
                         $response->maxvalue[] = $this->getpath($rs, array(0, '#'), '', true);
                    } else if ($rsid === 'vargte') {
                         $response->minvalue[] = $this->getpath($rs, array(0, '#'), '', true);
                    }
                }

            }
            if ($this->getpath($bbresponse,
                    array('#', 'displayfeedback', 0, '@', 'linkrefid'), false, false)) {
                $response->feedback = $this->getpath($bbresponse,
                        array('#', 'displayfeedback', 0, '@', 'linkrefid'), '', true);
            }

            // Determine what mark to give to this response.
            if ($this->getpath($bbresponse,
                    array('#', 'setvar', 0, '#'), false, false)) {
                $response->mark = (float)$this->getpath($bbresponse,
                        array('#', 'setvar', 0, '#'), '', true);
                if ($response->mark > 0.0) {
                    $response->title = 'correct';
                } else {
                    $response->title = 'incorrect';
                }
            }
            $responses[] = $response;
        }
    }

    /**
     * Preprocess XML blocks containing data for responses feedbacks.
     * Called by {@link create_raw_question()}
     * for all questions types.
     * @param array $feedbackset XML block to parse
     * @param array $feedbacks array of feedbacks suitable for a rawquestion.
     */
    public function process_feedback($feedbackset, &$feedbacks) {
        foreach ($feedbackset as $bbfeedback) {
            $feedback = new stdClass();
            $feedback->ident = $this->getpath($bbfeedback,
                    array('@', 'ident'), '', true);
            $feedback->text = '';
            if ($this->getpath($bbfeedback,
                    array('#', 'flow_mat', 0), false, false)) {
                $this->process_block($this->getpath($bbfeedback,
                        array('#', 'flow_mat', 0), false, false), $feedback);
            } else if ($this->getpath($bbfeedback,
                    array('#', 'solution', 0, '#', 'solutionmaterial', 0, '#', 'flow_mat', 0), false, false)) {
                $this->process_block($this->getpath($bbfeedback,
                        array('#', 'solution', 0, '#', 'solutionmaterial', 0, '#', 'flow_mat', 0), false, false), $feedback);
            }

            $feedbacks[$feedback->ident] = $feedback;
        }
    }

    /**
     * Create common parts of question
     * @param object $quest rawquestion
     * @return object Moodle question.
     */
    public function process_common($quest) {
        $question = $this->defaultquestion();
        $text = $this->cleaninput($quest->question->text);
        if ($quest->title) {
            $question->name = $this->cleaninput($quest->title);
        } else {
            $question->name = $this->create_default_question_name($text,
                    get_string('defaultname', 'qformat_canvas' , $quest->id));
        }

        $question->questiontext = $text;
        $question->questiontextformat = FORMAT_HTML;

        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;

        return $question;
    }

    /** Pocess different feebacks
     * @param object $quest rawquestion
     * @param object $question current Moodle question
     * @param boolean addcombined
     */
    protected function process_qfeedbacks($quest, &$question, $addcombined=true) {
        $feedback = new stdClass();
        foreach ($quest->feedback as $fb) {
            $feedback->{$fb->ident} = trim($fb->text);
        }
        if (isset($feedback->general_fb)) {
            $question->generalfeedback = $feedback->general_fb;
        }
        if ($addcombined) {
            if (isset($feedback->correct_fb)) {
                $question->correctfeedback['text'] = $feedback->correct_fb;
            }
            if (isset($feedback->general_incorrect_fb)) {
                $question->incorrectfeedback['text'] = $feedback->general_incorrect_fb;
            }
        }
    }

    protected function process_answer_feedback($quest, $ident) {
        if (isset($quest->feedback[$ident])) {
            return $this->cleaned_text_field($quest->feedback[$ident]->text);
        } else {
            return $this->cleaned_text_field('');
        }
    }

    /**
     * Process a multichoice singke answer question
     * Parse a multichoice single answer rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    protected function process_mc($quest, &$questions) {
        $gradeoptionsfull = question_bank::fraction_options_full();
        $question = $this->process_common($quest);
        $question->qtype = 'multichoice';
        $question = $this->add_blank_combined_feedback($question);
        $question->single = 1;

        $answers = $quest->responses;
        $correctanswers = array();
        foreach ($answers as $answer) {
            if ($answer->title == 'correct') {
                $answerset = $answer->ident;
                foreach ($answerset as $ans) {
                    $correctanswers[$ans] = 1;
                }
            }
        }
        $this->process_qfeedbacks($quest, $question, true);

        $i = 0;
        foreach ($quest->choices as $cid => $choice) {
            $question->answer[$i] = $this->cleaned_text_field(trim($choice->text));
            if (array_key_exists($choice->ident, $correctanswers)) {
                // Correct answer.
                $question->fraction[$i] = 1;
            } else {
                // Wrong answer.
                $question->fraction[$i] = 0;
            }
            $question->feedback[$i] = $this->process_answer_feedback($quest, $choice->ident .'_fb');
            $i++;
        }
        $questions[] = $question;
    }

    /**
     * Process a Multichoice multi question
     * Parse a multichoice multiple answer rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    protected function process_ma($quest, &$questions) {
        $gradeoptionsfull = question_bank::fraction_options_full();
        $question = $this->process_common($quest);
        $question->qtype = 'multichoice';
        $question = $this->add_blank_combined_feedback($question);
        $question->single = 0;

        $answers = $quest->responses;
        $correctanswers = array();
        foreach ($answers as $answer) {
            if ($answer->title == 'correct') {
                $answerset = $answer->ident;
                foreach ($answerset as $ans) {
                    $correctanswers[$ans] = 1;
                }
            }
        }

        $this->process_qfeedbacks($quest, $question, true);

        $correctanswersum = array_sum($correctanswers);
        $i = 0;
        foreach ($quest->choices as $cid => $choice) {
            $question->answer[$i] = $this->cleaned_text_field(trim($choice->text));
            if (array_key_exists($choice->ident, $correctanswers)) {
                // Correct answer.
                $question->fraction[$i] =
                        match_grade_options($gradeoptionsfull, $correctanswers[$choice->ident] / $correctanswersum, 'nearest');
            } else {
                // Wrong answer.
                $question->fraction[$i] = 0;
            }
            $question->feedback[$i] = $this->process_answer_feedback($quest, $choice->ident .'_fb');
            $i++;
        }
        $questions[] = $question;
    }

    /**
     * Process Short Answer Questions
     * Parse a fillintheblank rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param array $questions array of Moodle questions already done.
     */
    protected function process_sa($quest, &$questions) {
        $question = $this->process_common($quest);
        $question->qtype = 'shortanswer';
        $question->usecase = 0; // Ignore case.

        $this->process_qfeedbacks($quest, $question, false);

        $answers = array();
        $fractions = array();
        $feedbacks = array();

        // Find the correct answers.
        foreach ($quest->responses as $response) {
            if ($response->title == 'correct') {
                foreach ($response->ident as $correctresponse) {
                    if ($correctresponse != '') {
                        $answers[] = $correctresponse;
                        $fractions[] = 1;
                        $feedbacks[] = $this->text_field('');
                    }
                }
            }
        }

        // Set the answer feedbacks.
        foreach ($quest->responses as $response) {
            if ($response->title != 'correct') {
                foreach ($response->ident as $correctresponse) {
                    if ($correctresponse != '') {

                        foreach ($answers as $aid => $ans) {
                            if ($ans === $correctresponse) {
                                $feedbacks[$aid] = $this->process_answer_feedback($quest, $response->title);
                            }
                        }
                    }
                }
            }
        }

        // Adding catchall to so that students can see feedback for incorrect answers when they enter something,
        // the instructor did not enter.
        $answers[] = '*';
        $fractions[] = 0;
        if (isset($feedback['incorrect'])) {
            $feedbacks[] = $this->cleaned_text_field($feedback['incorrect']);
        } else {
            $feedbacks[] = $this->text_field('');
        }

        $question->answer = $answers;
        $question->fraction = $fractions;
        $question->feedback = $feedbacks;

        if (!empty($question)) {
            $questions[] = $question;
        }

    }

    /**
     * Process Essay Questions
     * Parse an essay rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    public function process_essay($quest, &$questions) {
        $question = $this->process_common($quest);
        $question->qtype = 'essay';

        $question->fraction[] = 1;
        $question->defaultmark = 1;
        if ($quest->qtype == 'file_upload_question') {
            $question->attachments = 1;
            $question->attachmentsrequired = 1;
            $question->responserequired = 0;
            $question->responseformat = 'editor';
            $question->responsefieldlines = 15;
        } else {
            $question->attachments = 0;
            $question->attachmentsrequired = 0;
            $question->responserequired = 1;
            $question->responseformat = 'editor';
            $question->responsefieldlines = 15;
        }
        $question->graderinfo = $this->text_field('');
        $question->responsetemplate = $this->text_field('');

        $questions[] = $question;
    }

    /**
     * Process Matching Questions
     * Parse a matching rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param array $questions array of Moodle questions already done.
     */
    public function process_matching($quest, &$questions) {
        $question = $this->process_common($quest);
        $question = $this->add_blank_combined_feedback($question);
        $question->qtype = 'match';

        $this->process_qfeedbacks($quest, $question, true);

        // Construction of the array holding correct choice fo each subquestion.
        $correctchoices = array();
        // Construction of the array holding all possible choices.
        $allchoices = array();
        foreach ($quest->subquestions as $subq) {
            foreach ($quest->responses as $resp) {
                if (isset($resp->ident) && $resp->ident == $subq->ident) {
                    $correct = $resp->correct;
                }
            }

            $correctchoices[$subq->ident] = $subq->choices[$correct]->text;
            foreach ($subq->choices as $choice) {
                if (!in_array($choice->text, $allchoices)) {
                    $allchoices[] = $choice->text;
                }
            }
        }

        foreach ($allchoices as $choice) {
            if ($choice != '') { // Only import non empty subanswers.
                $subanswer = html_to_text($this->cleaninput($choice), 0);
                $subquestion = '';
                // Fin all subquestions ident having this choice as correct.
                $fiber = array_keys ($correctchoices, $choice);
                foreach ($fiber as $subqid) {
                    // We have found a correspondance for this choice so we need to take the associated subquestion.
                    foreach ($quest->subquestions as $subq) {
                        if (strcmp ($subq->ident, $subqid) == 0) {
                            $subquestion = $subq->text;
                            break;
                        }
                    }
                    $question->subquestions[] = $this->cleaned_text_field($subquestion);
                    $question->subanswers[] = $subanswer;
                }

                if ($subquestion == '') { // Then in this case, $choice is a distractor.
                    $question->subquestions[] = $this->text_field('');
                    $question->subanswers[] = $subanswer;
                }
            }
        }

        // Verify that this matching question has enough subquestions and subanswers.
        $subquestioncount = 0;
        $subanswercount = 0;
        $subanswers = $question->subanswers;
        foreach ($question->subquestions as $key => $subquestion) {
            $subquestion = $subquestion['text'];
            $subanswer = $subanswers[$key];
            if ($subquestion != '') {
                $subquestioncount++;
            }
            $subanswercount++;
        }
        if ($subquestioncount < 2 || $subanswercount < 3) {
                $this->error(get_string('notenoughtsubans', 'qformat_canvas', $question->questiontext));
        } else {
            $questions[] = $question;
        }
    }

    /**
     * Process True / False Questions
     * Parse a truefalse rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param array $questions array of Moodle questions already done
     */
    protected function process_tf($quest, &$questions) {
        $question = $this->process_common($quest);

        $question->qtype = 'truefalse';
        $question->single = 1; // Only one answer is allowed.
        $question->penalty = 1; // Penalty = 1 for truefalse questions.

        $this->process_qfeedbacks($quest, $question, false);
        $responses = $quest->responses;
        foreach ($responses as $response) {
            if ($response->title == 'correct') {
                $correctresponseid = $response->ident[0];
                foreach ($quest->choices as $cid => $choice) {
                    if ($cid == $correctresponseid) {
                        $correctresponse = strtolower($choice->text);
                    } else {
                        $incorrectresponseid = $cid;
                    }
                }
            }
        }

        if ($correctresponse != 'false' && $correctresponse != 'faux'
                && $correctresponse != strtolower(get_string('false', 'qtype_truefalse'))) {
            $correct = true;
        } else {
            $correct = false;
        }

        if ($correct) {  // True is correct.
            $question->answer = 1;
            $question->feedbacktrue = $this->process_answer_feedback($quest, $correctresponseid .'_fb');
            $question->feedbackfalse = $this->process_answer_feedback($quest, $incorrectresponseid .'_fb');
        } else {  // False is correct.
            $question->answer = 0;
            $question->feedbacktrue = $this->process_answer_feedback($quest, $incorrectresponseid .'_fb');
            $question->feedbackfalse = $this->process_answer_feedback($quest, $correctresponseid .'_fb');
        }
        $question->correctanswer = $question->answer;
        $questions[] = $question;
    }

    /**
     * Process description Questions
     * Parse a description rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    public function process_description($quest, &$questions) {
        $question = $this->process_common($quest);
        $question->qtype = 'description';
        $question->defaultmark = 0;
        $question->length = 0;
        $questions[] = $question;
    }

    /**
     * Escape all characters used as control chars in cloze questions.
     * @param string $string text to escape
     * @return escaped text
     */
    protected function escape_text($string) {
        $controlchars = array('}', '#', '~', '/', '"', '\\');
        $escapedchars   = array('\\}', '\\#', '\\~', '\\/', '\\"', '\\\\');
        $string = str_replace($controlchars, $escapedchars, $string);
        return $string;
    }

    protected function construct_subquestion($placeholder, $choices, $responses, $subqtype) {
        $correctanswer = array();
        foreach ($responses as $response) {
            if ($response->title == 'correct' && $response->respident == 'response_' . $placeholder) {
                foreach ($response->ident as $choiceid) {
                    $correctanswer[] = $choiceid;
                }
            }
        }
        $subqtext = array();
        foreach ($choices as $cid => $choice) {
            // I think that for fill_in_multiple_blanks_question all answers are
            // correct despite what the XML file says.
            if (in_array($cid, $correctanswer) || $subqtype == 'fill_in_multiple_blanks_question') {
                $prefix = '%100%';
            } else {
                $prefix = '';
            }
            // TODO add the feedback if it exists.
            $subqtext[] = $prefix . $this->escape_text($choice->text);
        }
        switch($subqtype) {
            case 'fill_in_multiple_blanks_question':
                $subquestion = '{1:SHORTANSWER:' . implode('~', $subqtext) . '}';
                break;
            case 'multiple_dropdowns_question':
                $subquestion = '{1:MULTICHOICE:' . implode('~', $subqtext) . '}';
                break;
        }
        return $subquestion;
    }

    public function process_multiple($quest, &$questions, $questiontype) {
        $text = $this->cleaninput($quest->question->text);

        $placeholders = array();
        $subquestions = array();
        foreach ($quest->choices as $cid => $choice) {
            $subquestions[] = $this->construct_subquestion($cid, $choice, $quest->responses, $questiontype);
            $placeholders[] = '[' . $cid . ']';
        }

        $text = str_replace($placeholders, $subquestions, $text);

        $text = $this->cleaned_text_field($text);
        $question = qtype_multianswer_extract_question($text);

        $question->questiontext = $question->questiontext['text'];
        if ($quest->title) {
            $question->name = $this->cleaninput($quest->title);
        } else {
            $question->name = $this->create_default_question_name($text,
                    get_string('defaultname', 'qformat_canvas' , $quest->id));
        }

        $question->questiontextformat = FORMAT_HTML;
        $question->course = $this->course;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->qtype = 'multianswer';
        $this->process_qfeedbacks($quest, $question, false);
        $question->length = 1;
        $question->penalty = 0.3333333;

        $questions[] = $question;
    }

    public function process_num($quest, &$questions) {
        $gradeoptionsfull = question_bank::fraction_options_full();
        $question = $this->process_common($quest);

        $question->qtype = 'numerical';

        $this->process_qfeedbacks($quest, $question, false);

        $answers = array();
        $fractions = array();
        $feedbacks = array();
        // Find the correct answers.
        foreach ($quest->responses as $response) {
            if ($response->title === 'correct') {
                foreach ($response->minvalue as $ansid => $minans) {
                    $min = trim($minans);
                    $max = trim($response->maxvalue[$ansid]);
                    $ans = ($max + $min) / 2;
                    $tol = $max - $ans;
                    if ($response->mark > 0) {
                        $question->fraction[] = match_grade_options($gradeoptionsfull, $response->mark / 100, 'nearest');
                        $question->answer[] = $ans;
                        $question->tolerance[]  = $tol;
                        if (isset($response->feedback)) {
                            $question->feedback[] = $this->process_answer_feedback($quest, $response->feedback);
                        } else {
                            $question->feedback[] = $this->cleaned_text_field('');
                        }
                    }
                }
            }
        }
        $questions[] = $question;
    }

    public function process_calculated($quest, &$questions) {
        $question = $this->process_common($quest);
        echo "<hr /><p><b>Calculated question skipped: </b>. ".$this->format_question_text($question)."</p>";
    }
}
