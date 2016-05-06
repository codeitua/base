<?php
namespace CodeIT\Utils;

class Strings {

	static function generatePassword($length = 8, $onlyLowercase = false) {

		$chars = 'abcdefghijkmnoprqstuvwxyz23456789';
		if(!$onlyLowercase) {
			$chars .= 'ABCDEFGHIJKLMNPRQSTUVWXYZ';
		}

		$numChars = strlen($chars);

		$string = '';
		for ($i = 0; $i < $length; $i++) {
			$string .= substr($chars, rand(1, $numChars) - 1, 1);
		}
		return $string;
	}

	static function stripText($pagetext, $nchars=200, $link=false, $addAnchor=true)	{
		if(isset($nchars)&&(strlen($pagetext)>$nchars)) {
			$border="--codeit-border--";
			$pagetext=wordwrap($pagetext,$nchars,$border);
			$pt=explode($border,$pagetext);
			if(strlen($pt[0])<=$nchars) {
				$pagetext=$pt[0]."...";
			}
			else {
				$pagetext=substr($pt[0],0,$nchars)."...";
			}
			if($link) {
				if($addAnchor) {
					$link="<A HREF='$link'>read more</A>";
				}
				$pagetext.="&nbsp;$link";    }
		}
		return $pagetext;
	}

	static function SEOurlEncode($name){
		$values = [
			'-',
			' ',
		];
		$replace = [
			'_',
			'-',
		];

		return str_replace($values, $replace, $name);
	}

	static function SEOurlDecode($seoname){
		$values = [
			'_',
			'-',
		];
		$replace = [
			'-',
			' ',
		];

		return str_replace($values, $replace, $seoname);
	}

	static function str_replace_once($search, $replace, $text){ 
		$pos = strpos($text, $search); 
		return $pos !== false ? substr_replace($text, $replace, $pos, strlen($search)) : $text; 
	}

	static function clearLogin($string){
		$string = substr($string, 0, 15);
		$string = preg_replace('/[^A-Za-z0-9_]/', '_', $string);
		return $string;
	}

	static function trimArray(&$arr) {
		foreach($arr as $k => $val) {
			if(is_string($val)) $arr[$k] = trim($val);
		}
	}

	static function backtrace() {
		$output = "<div style='text-align: left; font-family: monospace;'>\n";
		$output .= "<b>Backtrace:</b><br />\n";
		$backtrace = debug_backtrace();

		foreach ($backtrace as $bt) {
			if(!isset($bt['class'])) {
				$bt['class'] = '';
			}
			if(!isset($bt['type'])) {
				$bt['type'] = '';
			}
			if(!isset($bt['file'])) {
				$bt['file'] = '';
			}
			if(!isset($bt['line'])) {
				$bt['line'] = '';
			}
			$args = '';
			foreach ($bt['args'] as $a) {
				if (!empty($args)) {
					$args .= ', ';
				}
				switch (gettype($a)) {
					case 'integer':
					case 'double':
						$args .= $a;
						break;
					case 'string':
						$a = htmlspecialchars(substr($a, 0, 64)).((strlen($a) > 64) ? '...' : '');
						$args .= "\"$a\"";
						break;
					case 'array':
						$args .= 'Array('.count($a).')';
						break;
					case 'object':
						$args .= 'Object('.get_class($a).')';
						break;
					case 'resource':
						$args .= 'Resource('.strstr($a, '#').')';
						break;
					case 'boolean':
						$args .= $a ? 'True' : 'False';
						break;
					case 'NULL':
						$args .= 'Null';
						break;
					default:
						$args .= 'Unknown';
				}
			}
			$output .= "<br />\n";
			$output .= "<b>file:</b> {$bt['line']} - {$bt['file']}<br />\n";
			$output .= "<b>call:</b> {$bt['class']}{$bt['type']}{$bt['function']}($args)<br />\n";
		}

		$output .= "</div>\n";
		echo $output;
	}

}
