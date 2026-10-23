<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

namespace LinguaFlowAI\Translation;

/**
 * A model answer was rejected (broken markers, missing protected term, all caps...): ask again.
 */
class RetryException extends \RuntimeException
{
}
