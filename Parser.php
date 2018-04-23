<?php


/* Grammar
 *
 * expression := [ log_term 'or' ]* log_term
 *
 * log_term := [ log_factor 'and' ]* log_factor
 *
 * log_factor := comparison
 *             | '(' expression ')'
 *             | '!' '(' expression ')'
 *
 * comparison := literal lt_op symbol lt_op literal
 *             | literal lt_op symbol
 *             | literal gt_op symbol gt_op literal
 *             | literal gt_op symbol
 *             | literal eq_op symbol
 *             | symbol relop literal
 *             | symbol ( isnull | notnull )
 *             | symbol ( in | notin ) in_group ')'
 *             | symbol ( 'like' | notlike ) str
 *             | kv_lit
 *
 * literal := int | str
 *
 *
 * Terminal (lexical) symbols:
 *
 * int := [0-9]+
 *
 * str := '"' [^"]* '"' | '\'' [&']* '\''
 *
 * symbol := [a-zA-Z] [a-zA-Z0-9_]*
 *
 * relop := lt_op | gt_op | eq_op
 *
 * lt_op := '<' | '<='
 *
 * gt_op := '>' | '>='
 *
 * eq_op := '=' | '!=' | '<>'
 *
 * isnull := 'is' 'null'
 *
 * notnull := 'is' 'not' 'null'
 *
 * in := 'in' '('
 *
 * notin := 'not' 'in' '('
 *
 * in_group := in_item [ ', ' in_item ]* ')'
 *
 * in_item := literal | 'null' | symbol
 *
 * kv_sym := ( 'hd' | 'isd' | 'tar' ) '-' [0-9]+
 */

/* Instruction types in parse-tree nodes are indexed arrays where the first element is the
 * opcode string.  Definition of the remaining array members depends on the opcode.  All
 * instructions return boolean values.
 *
 * ! (operand)
 *   Returns the logical NOT of the operand.
 *
 * >< (symbol, low-bound-is-inclusive, low bound, high-bound-is-inclusive, high bound)
 *   Tests whether the given symbol falls within the range specified by the low and high bounds.
 *   Bounds may be inclusive or exclusive.
 *
 * < | <= | = | <> | >= | > (symbol, value)
 *   Evaluates the expression "symbol op value" where 'op' is the same as the given opcode.
 *
 * isin (symbol, value*)
 *   Returns TRUE if symbol is equal to any of the given values
 *
 * isnull (symbol)
 *   Returns TRUE if the given symbol is null.
 *
 * like (symbol, value)
 *   Returns TRUE if symbol value is passes the LIKE test with the given value
 *
 * log_and (operand*)
 *   Takes the logical AND of the operands.  Does short-circuit execution: immediately
 *   returns FALSE in any operand evaluates to FALSE.
 *
 * log_or (operand*)
 *   Takes the logical OR of the operands.  Does short-circuit execution: immediately
 *   returns TRUE if any operand evaluates to TRUE;
 */
class Parser {
   private $source;
   private $tp;
   private $tokens;
   private $tokenpos;

   private static $swapop = array(
      '<' => '>',
      '<=' => '>=',
      '>' => '<',
      '>=' => '<=',
      '=' => '=',
      '<>' => '<>');
   private static $relopClass = array(
      '<' => '<',
      '<=' => '<=',
      '=' => '=',
      '<>' => '=',
      '>' => '>',
      '>=' => '>');

   function __construct($tp) {
      $this->tp = $tp;
   }

   function parse($source) {
      $this->source = $source;
      $this->tokenize($source);
      $this->tokenpos = 0;
      $parsed = $this->expression();
      if ($this->tokenpos < count($this->tokens)) {
         $this->failToken("expected expression");
      }
      return $parsed;
   }

   private function tokenize($source) {
      $tokens = array();
      $pos = 0;
      while (strlen($source)) {
         $type = $val = NULL;
         if (preg_match('/^\s*(-?\d+)\s*/', $source, $matches)) {
            $type = 'int';
            $val = $matches[1];
         }
         elseif (preg_match('/^\s*(["\'])(.*?)\1\s*/', $source, $matches)) {
            $type = 'str';
            $val = $matches[2];
         }
         elseif (preg_match('/^\s*(==|=|<=|>=|<>|<|>|!=|!)\s*/', $source, $matches)) {
            $type = $matches[1];
            if ('==' == $type) {
               $type = '=';
            }
            elseif ('!=' == $type) {
               $type = '<>';
            }
         } 
         elseif (preg_match('/^\s*is\s+(not\s+)?null\s*/i', $source, $matches)) {
            $type = empty($matches[1]) ? 'isnull' : 'notnull';
         } 
         elseif (preg_match('/^\s*(and|or|[(),])\s*/i', $source, $matches)) {
            $type = strtolower($matches[1]);
         } 
         elseif (preg_match('/^\s*(not\s+)?in\s*\(\s*/i', $source, $matches)) {
            $type = empty($matches[1]) ? 'isin' : 'isnotin';
         }
         elseif (preg_match('/^\s*not\s+like\s*/i', $source, $matches)) {
            $type = 'notlike';
         }
         elseif (preg_match('/^\s*(hd|tar|isd)-?(\d+)\s*/', $source, $matches)) {
            $type = 'kv_lit';
            $val = array($matches[1], $matches[2]);
         }
         elseif (preg_match('/^\s*([[:alpha:]]\w*)\s*/', $source, $matches)) {
            $type = 'sym';
            $val = $matches[1];
         } 
         else {
            $this->failPos($pos);;
         }
         $tokens[] = array($type, $val, 'pos' => $pos);
         $used = strlen($matches[0]);
         $source = substr($source, $used);
         $pos += $used;
      }
      $this->tokens = $tokens;
   }

   private function expression() {
      $term = $this->log_term();
      if (!$term) {
         return NULL;
      }
      $terms = array($term);
      for (;;) {
         $tok = $this->pickToken('or');
         if (!$tok) {
            break;
         }
         $term = $this->log_term();
         if (!$term) {
            $this->failToken("expected subexpression");
         }
         $terms[] = $term;
      }
      if (count($terms) > 1) {
         array_unshift($terms, 'log_or');
         return $terms;
      }
      return $terms[0];
   }

   private function log_term() {
      $factor = $this->log_factor();
      if (!$factor) {
         return NULL;
      }
      $factors = array($factor);
      for (;;) {
         $tok = $this->pickToken('and');
         if (!$tok) {
            break;
         }
         $factor = $this->log_factor();
         if (!$factor) {
            $this->failToken("expected subexpression");
         }
         $factors[] = $factor;
      }
      if (count($factors) > 1) {
         array_unshift($factors, 'log_and');
         return $factors;
      }
      return $factors[0];
   }

   private function log_factor() {
      if (($nt = $this->comparison())) {
         return $nt;
      }
      $negating = $this->pickToken('!');
      $open = $this->pickToken('(');
      if (!$open) {
         if ($negating) {
            $this->failToken("expected (");
         }
         return NULL;
      }
      $expr = $this->expression();
      if (!$expr) {
         $this->failToken("expected expression");
      }
      if ($negating) {
         return array('!', $expr);
      }
      return $expr;
   }

   private function comparison() {
      $tok_lit = $this->literal();
      if ($tok_lit) {
         $tok_op = $this->pickToken('<', '<=', '=', '<>', '>=', '>');
         if (!$tok_op) {
            $this->failToken("expected comparison operator");
         }
         $tok_sym = $this->pickToken('sym');
         if (!$tok_sym) {
            $this->failToken("expected symbol");
         }
         $this->checkSymbolValue($tok_sym, $tok_lit);
         $lit = $tok_lit[1];
         $op = $tok_op[0];
         $sym = $tok_sym[1];
         $tok_op2 = FALSE;
         $opclass = self::$relopClass[$op];
         if ('<' == $opclass) {
            $this->failIfUnsortable($sym);
            $tok_op2 = $this->pickToken('<', '<=');
         }
         else if ('>' == $opclass) {
            $this->failIfUnsortable($sym);
            $tok_op2 = $this->pickToken('>', '>=');
         }
         if ($tok_op2) {
            $tok_lit2 = $this->literal();
            if (!$tok_lit2) {
               $this->failToken("expected " . ($op{0} == '<' ? '< or <=' : '> or >='));
            }
            $this->checkSymbolValue($tok_sym, $tok_lit2);
            $op2 = $tok_op2[0];
            $lit2 = $tok_lit2[1];
            if ('<' == $opclass) {
              $lower = $lit;
              $lowEQ = $op != '<';
              $upper = $lit2;
              $uppEQ = $op2 != '<';
            }
            else {
              $lower = $lit2;
              $lowEQ = $op2 != '>';
              $upper = $lit;
              $uppEQ = $op != '>';
            }
            if ($lower > $upper) {
              $this->failToken("low end of comparison range is greater than high end", -5);
            }
            return array('><', $sym, $lowEQ, $lower, $uppEQ, $upper);
         }
         return array(self::$swapop[$op], $sym, $lit);
      }
      $sym = $this->pickToken('sym');
      if ($sym) {
         $op = $this->pickToken('=', '<>', '<', '<=', '>=', '>');
         if ($op) {
            $lit = $this->literal();
            if (!$lit) {
               $this->failToken("expected string or integer");
            }
            if ('=' == self::$relopClass[$op[0]]) {
              $this->failIfUnsortable($sym);
            }
            $this->checkSymbolValue($sym, $lit);
            $sym = $sym[1];
            $op = $op[0];
            $lit = $lit[1];
            return array($op, $sym, $lit);
         }
         $op = $this->pickToken('isnull', 'notnull');
         if ($op) {
            $this->checkSymbolValue($sym);
            $out = array('isnull', $sym[1]);
            if ('notnull' == $op[0]) {
               $out = array('!', $out);
            }
            return $out;
         }
         $op = $this->pickToken('isin', 'isnotin');
         if ($op) {
            $val = $this->literalOrSymbol();
            if (!$val) {
               $this->failToken("expected literal or symbol");
            }
            $list = array($val);
            for (;;) {
               $tok = $this->pickToken(',');
               if ($tok) {
                  $val = $this->literalOrSymbol();
                  if (!$val) {
                     $this->failToken("expected literal or symbol");
                  }
                  $list[] = $val;
               }
               else {
                  if (!$this->pickToken(')')) {
                     $this->failToken("expected )");
                  }
                  break;
               }
            }
            $reduced = array();
            $nullable = FALSE;
            foreach ($list as $tok) {
               list($type, $val) = $tok;
               if ('sym' == $type && 'null' == $val) {
                  $nullable = TRUE;
               }
               else {
                  $this->checkSymbolValue($sym, $tok);
                  $reduced[$val] = $val;
               }
            }
            $sym = $sym[1];
            $out = NULL;
            if ($reduced) {
               $out = array_merge(array('isin'), array_values($reduced));
            }
            if ($nullable) {
               if ($out) {
                  $out =  array('or', array('isnull', $sym), $out);
               }
               else {
                  $out = array('isnull', $sym);
               }
            }
            if ($op[0] == 'isnotin') {
               $out = array('!', $out);
            }
            return $out;
         }
         $op = $this->pickToken(array('type' => 'notlike', 'sym' => 'like'));
         if ($op) {
            $this->checkSymbolValue($sym);
            $val = $this->pickToken('str');
            $out = array('like', $sym[1], $val[1]);
            if ($op[0] == 'notlike') {
               $out = array('!', $out);
            }
            return $out;
         }
         $this->failToken("expected relation operator or 'is', 'like', or 'not'");
      }
      $kv = $this->pickToken('kv_lit');
      if ($kv) {
         return array('=', $kv[1][0], $kv[1][1]);
      }
      return NULL;
   }


   private function failToken($message, $offset = 0) {
      if ($this->tokenpos + $offset > count($this->tokens) - 1) {
         $pos = strlen($this->source);
      }
      elseif ($this->tokenpos + $offset < 0) {
         $pos = 0;
      }
      else {
         $pos = $this->tokens[$this->tokenpos + $offset]['pos'];
      }
      throw new Exception("$pos|$message");
   }

   private function pickToken($a) {
      if ($this->tokenpos > count($this->tokens) - 1) {
         return NULL;
      }
      $token = $this->tokens[$this->tokenpos++];
      $type = $token[0];
      if (is_array($a)) {
         $val = $token[1];
         foreach ($a as $k => $v) {
            if ('type' == $k) {
               if ($v == $type) {
                  return $token;
               }
            }
            elseif ($k == $type && $v == $val) {
               return $token;
            }
         }
      }
      else {
         foreach (func_get_args() as $cand) {
            if ($type == $cand) {
               return $token;
            }
         }
      }
      --$this->tokenpos;
      return NULL;
   }

   private function literal() {
      return $this->pickToken('str', 'int');
   }

   private function literalOrSymbol() {
      return $this->pickToken('str', 'int', 'sym');
   }

   private function failIfUnsortable($sym) {
     if ('status' == $sym) {
       $this->failToken("$sym does not allow less-than/greater-than comparisons", -1);
     }
   }

   private function checkSymbolValue($sym, $value = NULL) {
   }
}

