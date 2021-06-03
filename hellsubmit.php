<!DOCTYPE HTML>  
<html>
<head>
<style>
.error {color: #FF0000;}
.body {background-color: #FFFFFF;}
</style>
</head>
<body style="background-color: #000000; color:#FFFFFF">

<?php
//DFO Luck Calculator project - just a short php script to safely save values and show some per-character statistics, TODO: allow edit/deletes, show global stats, show histories
// define variables and set to empty values
require_once("/data/keys/postgres_connection_string.sql");
$_REQUEST["fragcount"] = 0;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ERROR);
$pg = pg_connect($connection_string);
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  //Alll of this junk is just processing the $_REQUEST-containing form data
  $charname = $_REQUEST["charname"];
  $_REQUEST["lookupcharname"] = $charname; //If they're submitting runs, maybe they'd like to see the history in the bottom right
  $channel = $_REQUEST["channel"];
  $difficulty = $_REQUEST["difficulty"];
  $fragcount = ($_REQUEST["fragcount"]!==""?$_REQUEST["fragcount"]:"0");
  $stonecount = ($_REQUEST["stonecount"]!==""?$_REQUEST["stonecount"]:"0");
  $wisremains = ($_REQUEST["wisremains"]!==""?$_REQUEST["wisremains"]:"0");
  $goworbs = ($_REQUEST["goworbs"]?:"0");
  $souls = ($_REQUEST["souls"]?:"0");
  $epics = ($_REQUEST["epiccount"]?:"0");
  $pows = ($_REQUEST["pows"]?:"0");
  $goldorb = (isset($_REQUEST["goldorb"])?"1":"0");
  $rainbow = (isset($_REQUEST["rainbow"])?"1":"0");
  $demon = (isset($_REQUEST["demonspawn"])?"1":"0");
  $wish = (isset($_REQUEST["epicwish"])?"1":"0");
  $mythicdrop = (isset($_REQUEST["mythicdrop"])?"1":"0");
  $partysize = (isset($_REQUEST["partymembers"])?$_REQUEST["partymembers"]:"1"); //If they left it blank, 99.99% of the time it's a solo runner, just assume 1, wil solve more problems than it creates
  $potion = (isset($_REQUEST["potion"])?"1":"0"); //New as of 6/1/2021, epic drop rate potion tracking stats
  if($rainbow) {
    //Some basic sanity checks - can't have a rainbow without a gold
    $goldorb = "1";
  }
  if($demon) {
    //Some basic sanity checks - can't have a demon withou a rainbow or mythic drop
    $rainbow = "1";
    $goldorb = "1";
    $mythicdrop = "1";
  }

  if($mythicdrop) {
    //Yes, it's redundant to have both a demon and a mythic checkbox, but trust me, when a mythic drops, you're too busy being happy to care - something satisfying about the ritual
    $rainbow = "1";
    $goldorb = "1";
    $demon = "1";
  }

  $formid=$_REQUEST["formid"];
  //The form ID helps prevent two things - spam submissions and double-clicks.  By forcing them to submit a new form with a new form ID, makes it much simpler to 
  //Since this all saves by $_REQUEST, you can technically just submit a RESTful GET string with the proper variables, and it should save that way - allowing for easy external automation




  //Actual database save, using parameterized values
  $querystring = "insert into gow_run (charname, channel, difficulty, wisdom_crystal_fragments, tg_stones, wisdom_remnants, epic_souls, gow_orbs, epics, gold_orb, rainbow_orb, wish, mythic, party_size, pows, potion, id)";
  $querystring .= " values            (LOWER($1),       $2,      $3,         $4,                       $5,        $6,              $7,         $8,       $9,    $10,      $11,         $12,  $13,    $14, $15, $16, $17) ";
  
  $arrParams = array();
  $arrParams[0] = $charname;
  $arrParams[1] = $channel;
  $arrParams[2] = $difficulty;
  $arrParams[3] = 0; 
  $arrParams[4] = $stonecount;
  $arrParams[5] = $wisremains;
  $arrParams[6] = $souls;
  $arrParams[7] = $goworbs;
  $arrParams[8] = $epics;
  $arrParams[9] = $goldorb;
  $arrParams[10] = $rainbow;
  $arrParams[11] = $wish;
  $arrParams[12] = $mythicdrop; 
  $arrParams[13] = $partysize;
  $arrParams[14] = $pows;
  $arrParams[15] = $potion;
  $arrParams[16] = $formid;
//  $arrParams[13] = $npp;
//  $arrParams[14] = $vip;
  foreach($arrParams as $index=>$val) {
    $arrParams[$index] = trim($val);
  }

  //Error checking - make sure character names are valid,
  $errors = "";
  if($arrParams[0] === "" || strlen($arrParams[0]) < 4 || strlen($arrParams[0] > 15)) {
    $errors .= "Character name is not valid, please enter one!<br>";
  }
  //The channel numbers are valid (removed the old channel whitelist due to 6/1 patch)
  if($arrParams[1] === "" || !is_numeric($arrParams[1]) || $arrParams[1] < '1') {
    $errors .= "Channel number is not numeric!<br>";
  }

  //difficulty check
  if($arrParams[2] !== "normal" && $arrParams["2"]!=="expert") {
    $errors .= "Difficulty is not valid, please pick one!<br>";
  }

  //Party size
  if($arrParams[13] < 1 || $arrParams[13] > 4) {
    $errors .= "Party size is not valid! Must be 1, 2, 3 or 4, not ".$arrParams[13]."!";
  }

  //For everything else, we mostly care that it's a number - it'll be filtered out if they submit junk, but it needs to at least be valid junk
  foreach($arrParams as $index=>$val) {
    if($index < 3 || $index > 15) { //Everything here's either an int or a bool that we pretend is an int because it's convenient
      continue;
    }
    if(!is_numeric($val) && $val!=="") {
      $errors .= "Provided parameter number $index is non-numeric!  Value given: $val<br>";
      echo (is_numeric($val)?"$val is numeric":"$val is not numeric");
    }
  }

  //Make sure they're not accidentally double-tapping the submit button
  $check_query = "select id from gow_run where id=$1";
  $arrcheck[0] = $formid;

  $check_for_existing_row = pg_query_params($pg, $check_query, $arrcheck);
  $existing_row = pg_fetch_array($check_for_existing_row, null, PGSQL_ASSOC);

  if($existing_row !== false) {
    //oh shit they did it, error out
    $errors .= "You tried to submit a duplicate run with the ID $formid = don't do that!\n";
  }

  if($errors==="") {
    //Output the ID and some data, which helps people keep track of which run they're submitting, along with the live counter in the bottom right
    echo "Hell Saved! Run the next one!<br>";
    echo "ID: <span style=\"color:#DD2222\">$formid</span><br>";
    echo "Data: $stonecount stones, $souls souls, $wisremains wisdom remains<br>";
    if($goworbs) {
      echo "grats on the <span style=\"color:#ce00ce;\">G O W   O R B!</span><br>";
    }

    if($epics) {
      //some coloration is fun
      echo "Ayy, <span style=\"color:gold;\">epic(s!)</span>  Hope it was useful!<br>";
      if(!$goldorb) {
        echo "holy shit, <span style=\"color:#800080\">purple</span> orb <span style=\"color:gold;\">epic!?!</span><br>";
      }
    }

    if($wish) {
      echo " <span style=\"color:gold;\">EPIC   WISH!!!!! OMFG!!!</span><br>";
    }

    if($mythicdrop) {
      //Totally worth
      echo "<span style=\"color:#FF0000\">M</span>";
      echo "<span style=\"color:#FFA500\">Y</span>";
      echo "<span style=\"color:#BBBB00\">T</span>";
      echo "<span style=\"color:#00FF00\">H</span>";
      echo "<span style=\"color:#0000FF\">I</span>";
      echo "<span style=\"color:#800080\">C</span>";
      echo "<span style=\"color:gold;\">!!!!!</span><br>";
    }


    pg_query_params($pg, $querystring, $arrParams);
  } else {
    echo "ERRORS!<br> $errors<br>";
  }

} else {
  //If we're not submitting a run, just show the basic instructions
	echo "GOW Tracker v0.06!<br>
  Submission Guidelines:  Please set your char name, party size, channel and difficulty prior to runs, and submit runs as you do them.  Do not selectively report runs - report everything, no matter how insanely good or insanely bad!  
  If you leave a spot blank, it will assume that 0 dropped.";
}

  $uuid = pg_query($pg, "select gen_random_uuid() as formid");
  $formid = pg_fetch_all($uuid)[0]["formid"];

  //Form is displayed below - just a hardcoded form, should absract to a class if I want to re-use for things like tracking Sirocco drops, Purgatory, EM etc.
?>

<h2>GOW Data Submission Form</h2>
<div style="margin:0; display:inline-block; width:50%;">
HELL ID:<span style="color:#CC2222;"><?php echo $formid;?></span>
<br>
<form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">  
  <input type="hidden" name="formid" id="formid" value="<?php echo $formid;?>">
  Character Name: <input type="text" name="charname" id="charname" value="<?php echo $charname;?>">
  <br><br>
  Channel: <input type="number" id="channel" name="channel" value="<?php echo $channel;?>">
  <br><br>
  Party Size (INCLUDING YOU): <input type="number" id="partymembers" name="partymembers" value="<?php echo ($partysize?:"1")?>" min="1" max="4">
  <br><br>
<?php
echo 'Difficulty: <select id="difficulty" name="difficulty">';
$options = array(""=>"", "normal"=>"Normal", "expert"=>"Expert");
foreach($options as $id=>$val) {
  if($difficulty === $id) {
   echo '<option value="'.$id.'" selected>'.$val.'</option>';
  } else {
    echo '<option value="'.$id.'">'.$val.'</option>';
  }
}
?>

  </select>

  <div></div>
  <?php
  //We added a new Potiion Active? checkbox - TODO maybe, disable after 5 runs unless they re-enable it?  Could add a reminder to pot here
  echo 'Potion Active? <input type="checkbox" id="potion" name="potion"';
  if($potion) {
    echo " checked "; 
  }
  echo ">";
  ?>
  <div></div>
  Stones: <input type="number" id="stonecount" name="stonecount" min="0" max="20"> 
  <div></div>
  Wisdom Remains: <input type="number" id="wisremains" name="wisremains" min="0" max="15"> 
  <div></div>
  Epic Souls: <input type="number" id="souls" name="souls" min="0" max="8"> 
  <div></div>
  GoW Orbs: <input type="number" id="goworbs" name="goworbs" min="0" max="2">
  <div></div>
  Non-Tradeable Epics: <input type="number" id="epiccount" name="epiccount" min="0" max="5"> 
  <div></div>
  Tradeable Epics: <input type="number" id="pows" name="pows" min="0" max="5"> 
  <div></div>
  Golden Orb? <input type="checkbox" id="goldorb" name="goldorb">
  <div></div>
  Rainbow Orb? <input type="checkbox" id="rainbow" name="rainbow">
  <div></div>
  Epic Wish?  <input type="checkbox" id="epicwish" name="epicwish">
  <div></div>
  MYTHIC DROPPED? <input type="checkbox" id=mythicdrop name="mythicdrop">
  <div></div>
	
  <input type="submit" name="submit" value="Submit">  
  <br><br>
</form>
</div><div style="margin:0; display:inline-block; width:50%;">
<?php
  if($_REQUEST["lookupcharname"]) {
    //Fetch some data from the database about how many runs/drops this person's done - we also auto-look-up when submitting a run
    echo "Your character name is ".$_REQUEST["lookupcharname"]."!<br>";
    $arrLookupParams[0] = strtolower($_REQUEST["lookupcharname"]);
    $result = pg_query_params($pg, "select count(*) from gow_run where charname=LOWER($1)", $arrLookupParams);
    $result_array = pg_fetch_all($result);
    $hellcount = pg_fetch_all($result)[0]["count"];
    echo "You have run ".$hellcount." hell(s)!<br>";
    $result = pg_query_params($pg, "select sum(tg_stones) from gow_run where charname=LOWER($1)", $arrLookupParams);
    $result_array = pg_fetch_all($result);
    $stonecount = pg_fetch_all($result)[0]["sum"];
    echo "You have gathered ".($stonecount?:"0")." stone(s)!<br>";
    $result = pg_query_params($pg, "select sum(epic_souls) from gow_run where charname=LOWER($1)", $arrLookupParams);
    $result_array = pg_fetch_all($result);
    $soulcount = pg_fetch_all($result)[0]["sum"];
    echo "You have found ".($soulcount?:"0")." epic soul(s)!<br>";
    $result = pg_query_params($pg, "select sum(epics) as epics, sum(pows) as pows  from gow_run where charname=LOWER($1)", $arrLookupParams);
    $result_array = pg_fetch_all($result);
    $epics = pg_fetch_all($result)[0]["epics"];
    $pows = pg_fetch_all($result)[0]["pows"];
    echo "You have found ".($epics?:"0")." proper epic(s)!<br>";
    echo "You have found ".($pows?:"0")." tradeable epic(s)!<br>";
    $result = pg_query_params($pg, "select count(*) as mythics from gow_run where charname=LOWER($1) and mythic='t'", $arrLookupParams);
    $mythics = pg_fetch_all($result)[0]["mythics"];
    if($mythics > 0) {
	    echo "Oh Baby!  You have found ".($mythics?:"0")." Mythic(s)!<br>";
    } else {
	    echo "You have found no mythics... yet!";
      //YET, I TELL YOU!  We'll all make it some day :D
    }
  }
  echo '<form method="get" action="'. htmlspecialchars($_SERVER["PHP_SELF"]).'">'; 
  echo 'Character Lookup:: <input type="text" name="lookupcharname" id="lookupcharname" value="'.$_REQUEST["lookupcharname"].'">';
  echo '<input type="submit" name="submit" value="Submit">'; 
  echo '</form>';
?>
</div>


</body>
</html>

