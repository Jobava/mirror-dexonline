<?php
require_once("../phplib/util.php");
header("Content-Type: application/json");


$acEnable = Config::get("search.acEnable");
$acMinChars = Config::get("search.acMinChars");
$acLimit = Config::get("search.acLimit");

$search = $_REQUEST["term"];


if (!$acEnable || strlen($search) < $acMinChars) {
  return print(json_encode(array()));
}


$arr = StringUtil::analyzeQuery($search);
$hasDiacritics = session_user_prefers(Preferences::FORCE_DIACRITICS) || $arr[0];


$sql_like = sprintf("%s%%", $search);

$search_results = Lexem::searchLike($sql_like, $hasDiacritics, true, $acLimit);


$clean_results = array_map(function($lexem) use ($hasDiacritics){
  if ($hasDiacritics) {
    return $lexem->formNoAccent;
  } else {
    return $lexem->formUtf8General;
  }
}, $search_results);


return print(json_encode($clean_results));

?>

