<?php
namespace Vimeo\MysqlEngine\Parser;

use Vimeo\MysqlEngine\TokenType;
use Vimeo\MysqlEngine\Query\Expression\PlaceholderExpression;
use Vimeo\MysqlEngine\Query\Expression\Expression;
use Vimeo\MysqlEngine\Query\Expression\BinaryOperatorExpression;
use Vimeo\MysqlEngine\Query\Expression\ColumnExpression;
use Vimeo\MysqlEngine\Query\Expression\ConstantExpression;

final class SetParser
{
    /**
     * @var int
     */
    private $pointer;

    /**
     * @var array<int, array{type:TokenType::*, value:string, raw:string}>
     */
    private $tokens;

    /**
     * @param array<int, array{type:TokenType::*, value:string, raw:string}> $tokens
     */
    public function __construct(int $pointer, array $tokens)
    {
        $this->pointer = $pointer;
        $this->tokens = $tokens;
    }

    /**
     * @return array{0: int, 1: array<int, BinaryOperatorExpression>}
     */
    public function parse(bool $skip_set = false)
    {
        if (!$skip_set && $this->tokens[$this->pointer]['value'] !== 'SET') {
            throw new SQLFakeParseException("Parser error: expected SET");
        }
        $expressions = [];
        $this->pointer++;
        $count = \count($this->tokens);
        $needs_comma = false;
        $end_of_set = false;

        while ($this->pointer < $count) {
            $token = $this->tokens[$this->pointer];

            switch ($token['type']) {
                case TokenType::NUMERIC_CONSTANT:
                case TokenType::STRING_CONSTANT:
                case TokenType::NULL_CONSTANT:
                case TokenType::OPERATOR:
                case TokenType::SQLFUNCTION:
                case TokenType::IDENTIFIER:
                case TokenType::PAREN:
                    if ($needs_comma) {
                        throw new SQLFakeParseException("Expected , between expressions in SET clause");
                    }
                    $expression_parser = new ExpressionParser($this->tokens, $this->pointer - 1);
                    $start = $this->pointer;
                    list($this->pointer, $expression) = $expression_parser->buildWithPointer();

                    if (!$expression instanceof BinaryOperatorExpression || $expression->operator !== '=') {
                        throw new SQLFakeParseException("Failed parsing SET clause: unexpected expression");
                    }
                    if (!$expression->left instanceof ColumnExpression) {
                        throw new SQLFakeParseException("Left side of SET clause must be a column reference");
                    }
                    $expressions[] = $expression;
                    $needs_comma = true;
                    break;
                case TokenType::SEPARATOR:
                    if ($token['value'] === ',') {
                        if (!$needs_comma) {
                            throw new SQLFakeParseException("Unexpected ,");
                        }
                        $needs_comma = false;
                    } else {
                        throw new SQLFakeParseException("Unexpected {$token['value']}");
                    }
                    break;
                case TokenType::CLAUSE:
                    $end_of_set = true;
                    break;
                default:
                    throw new SQLFakeParseException("Unexpected {$token['value']} in SET");
            }
            if ($end_of_set) {
                break;
            }
            $this->pointer++;
        }
        if (!\count($expressions)) {
            throw new SQLFakeParseException("Empty SET clause");
        }
        return [$this->pointer - 1, $expressions];
    }
}

