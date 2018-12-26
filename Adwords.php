<?php

require_once '/googleads-php-lib/examples/AdWords/v201502/init.php';
require_once ADWORDS_UTIL_PATH . '/ReportUtils.php';

$accountTimezone = "Europe/London";
// Change to your account's timezone

$accountName = "Which Technology2";
// Change to your account's name

$accountId = "xxx-xxx-xxxx";
// Change to your account's customer ID, if required.

$timeToRun = "02:00:00";
// The approximate time for the script to download data.
// The default is to run for 2 hours


//////////////////////////////////////////////////////////////////////////////////
// Main body

date_default_timezone_set($accountTimezone);
$cooldown = 0;

$fileHandles = makeFileHandles();
if ($fileHandles == null) {
 // Files could not be opened, so the script cannot run
 die;
}

$AdWordsUser = new AdWordsUser();
if ($accountId != "xxx-xxx-xxxx") {
 $AdWordsUser->SetClientCustomerId($accountId);
}

// Convert $timeToRun into seconds
$timeBits = explode(":",$timeToRun);
$secondsToRun = ($timeBits[0]*60*60) + ($timeBits[1]*60) + $timeBits[2];

for ($n=0; $n 0) {
// The data was different within the last minute, so there's no point looking now
$cooldown--;
} else {
// Get the latest report, compare with the previous report.
$different = compareNewReport($AdWordsUser, $fileHandles, $n);
if ($different === true) {
// If the new data was different then there should be a cooldown period
$cooldown = 2;
}
}
// Sleep for 30 seconds, before checking again
sleep(30);
}

echo "Finished fetching data \n";

// Gets final averages and outputs into the summary document.
$updateTimes = getAverageTimes($fileHandles);
$hourUpdateTime = getHourAverage($fileHandles);

$resultText = "Results of the API Update Checker for " . $accountName . " (at ". date("Y-m-d H:i:s") . ")\r\n"
. "Average time between updates: ".$updateTimes["avg"]. "\r\n"
. "Maximum time between updates: ".$updateTimes["max"]. "\r\n"
. "Minimum time between updates: ".$updateTimes["min"]. "\r\n"
. "Average age of data: " . $hourUpdateTime . "\r\n"
."\r\n";
fwrite($fileHandles["Results"],$resultText);

// Close all the file handles
foreach ($fileHandles as $name => $handle) {
fclose($handle);
}

unlink("tempdatastore.csv");

//////////////////////////////////////////////////////////////////////////////////
// Functions

function makeFileHandles() {
// Opens or creates the required files
// Returns an array containing the file handles

try {
$fileHandles = array();

if (!file_exists("log.csv")) {
$fileHandles["Log"] = fopen("log.csv","c+");
fputcsv($fileHandles["Log"],array("Date","Hour of day","Impressions","Clicks", "Time Downloaded", "Time Since Last Update"));
} else {
$fileHandles["Log"] = fopen("log.csv","c+");
}

if (!file_exists("hour-data.csv")) {
$fileHandles["Hour Data"] = fopen("hour-data.csv","c+");
fputcsv($fileHandles["Hour Data"],array("Hour of Day","Data First Appeared","Data Last Updated"));

$hour = "";
$startOfYesterday = date("Y-m-d",time() - 60 * 60 * 24)." 00:00:00";
for ($i=1; $igetMessage() . "\n";
$working = false;
}

// Check the handles are working, exit if any are not
foreach ($fileHandles as $name => $handle) {
if ($handle === false) {
echo $name . " could not be opened. The file might be in use by another program. \n";
$working = false;
}
}
if ($working) {
return $fileHandles;
} else {
return null;
}
} // end function makeFileHandles

function compareNewReport($AdWordsUser, $fileHandles, $n) {
// Downloads an AdWords report and compares it to the previous download
// (in the temp data store).
// If there is a difference:
// * the data is recorded in the log file
// * the newer data replaces the old in the temp data store file
// * the time of the update is recorded in the summary file
// * information on when each hour's data is updated is recorded in the hour data file
// The function returns TRUE if the most recent report was different to the last

try {
$fileSafeDate = date("H_i_s");
$fullDate = date("Ymd H:i:s");
$today = date("Ymd");
$yesterday = date("Ymd", time() - 60 * 60 * 24);

// Downloads an account performance report to get impressions and clicks by the hour, for today and yesterday
$AdWordsReportFilePath = $fileSafeDate . "_report.csv";
$reportDownloadedSuccessfully = DownloadCriteriaReport($AdWordsUser, $AdWordsReportFilePath, $yesterday, $today);
if (!$reportDownloadedSuccessfully) {
echo "Report could not be downloaded. \n";
return;
}

// Reads the report
$handleAdWordsReport = fopen($AdWordsReportFilePath, "r");
$newData = array();
$j = 0;
$latestHour = "19700101 00:00:00";

if ($handleAdWordsReport === false) {
echo "Could not open report file. \n";
return;
}

while(!feof($handleAdWordsReport)){
$a = fgetcsv($handleAdWordsReport);
if($j === 0){
$j = 1;
continue;
}
if($a[0] === "Total"){
break;
}

$currentHour = $a[0]." ".str_pad($a[1], 2, "0", STR_PAD_LEFT).":00:00";
$newData[$currentHour] = $a;

if (strtotime($currentHour) > strtotime($latestHour)) {
$latestHour = $currentHour;
}
}
fclose($handleAdWordsReport);
unlink($AdWordsReportFilePath);

// Read the last set of data, in $fileHandles["Temp Data Store"], and see if there are differences
$different = false;
$tempDataExists = true;
$newHourData = false;
$j = 0;
$hoursChanged = array();
rewind($fileHandles["Temp Data Store"]);
if (feof($fileHandles["Temp Data Store"])) {
$different = true;
$tempDataExists = false;
}

while(!feof($fileHandles["Temp Data Store"])) {
$a = fgetcsv($fileHandles["Temp Data Store"]);

if($a[0] === "Total" || $a[0] == ""){
if ($j === 0) {
$different = true;
$tempDataExists = false;
}
break;
}

$currentHour = $a[0]." ".str_pad($a[1], 2, "0", STR_PAD_LEFT).":00:00";

if ($j == 0 ) {
if (strtotime($latestHour) > strtotime($currentHour)) {
$newHourData = true;
echo "New hour data for " . $latestHour . "\n";
$different = true;
$oldTime = strtotime($a[count($a)-1]);
$hoursChanged[] = $latestHour;
}
$j = 1;
}

if (!isset($newData[$currentHour])) {
$different = true;
$oldTime = strtotime($a[count($a)-1]);
$hoursChanged[] = $currentHour;
} else {
for ($i=1; $i 0) {
// If $n is 0, this is the first time the function has run,
// so we don't record the time difference
$timeDifference = secondsToHours($newTime - $oldTime);
}

// Read the "Hour Data" file and update if the data for an hour has changed,
// or a new hour has started
rewind($fileHandles["Hour Data"]); //get the pointer to the start of the file
while (!feof($fileHandles["Hour Data"])) {
$a = fgetcsv($fileHandles["Hour Data"]);
if (!$a) {
break;
}
if (array_search($a[0],$hoursChanged) !== false) {
$hourData[$a[0]] = array($a[0], $a[1], $fullDate);
} else {
$hourData[$a[0]] = $a;
}
}

if ($newHourData) {
foreach ($newData as $hour => $row) {
if (!isset($hourData[$hour])) {
if ($tempDataExists) {
$hourData[$hour] = array($hour, $fullDate, $fullDate);
$startHour = new DateTime($hour);
$endHour = new DateTime($fullDate);
$diffHours = $startHour->diff($endHour);
} else {
// We can't be sure that this is the earliest the hour's data appeared
// so we don't record a 'First Update' time
$hourData[$hour] = array($hour, "-", $fullDate);
}
}
} // end foreach
krsort($hourData);
}

rewind($fileHandles["Hour Data"]);
$h = 0;
foreach ($hourData as $row) {
fputcsv($fileHandles["Hour Data"], $row);
$h++;
if ($h > 48) {
break;
}
}
ftruncate($fileHandles["Hour Data"],ftell($fileHandles["Hour Data"]));

// Write the temp data store, log and summary only when there are changes
rewind($fileHandles["Temp Data Store"]);
fseek($fileHandles["Log"],0,SEEK_END);

fseek($fileHandles["Summary"],0,SEEK_END);
fputcsv($fileHandles["Summary"],array($fullDate,$timeDifference));

krsort($newData);
foreach ($newData as $hour => $row) {
$row[] = $fullDate;
fputcsv($fileHandles["Temp Data Store"],$row);
$row[] = $timeDifference;
fputcsv($fileHandles["Log"],$row);
}
ftruncate($fileHandles["Temp Data Store"],ftell($fileHandles["Temp Data Store"]));

getAverageTimes($fileHandles);
getHourAverage($fileHandles);
} else {
echo "Data is the same. \n";
}

return $different;

} catch (Exception $e) {
echo $e->getMessage() . "\n";
return false;
}
} // end function getNewData

function getAverageTimes($fileHandles) {
// This reads the summary file and calculates the average
// time between updates

$count = 0;
$total = 0;
$max = 0;
$min = 999999;
$output = array();

rewind($fileHandles["Summary"]);
while (!feof($fileHandles["Summary"])) {
$a = fgetcsv($fileHandles["Summary"]);
if (!empty($a[1]) && $a[1] != "-" && $a[1] != "Time Since Last Update") {
$count++;

$timeBits = explode(":",$a[1]);
$timeInSeconds = ($timeBits[0]*60*60) + ($timeBits[1]*60) + $timeBits[2];
$total += $timeInSeconds ;
if ($timeInSeconds > $max) {
$max = $timeInSeconds;
}
if ($timeInSeconds 0 && $total > 0) {
$output["avg"] = secondsToHours($total/$count);
} else {
$output["avg"] = "-";
}
if ($max > 0) {
$output["max"] = secondsToHours($max);
} else {
$output["max"] = "-";
}
if ($min 0 && $total > 0) {
$avg = secondsToHours($total / $count);
echo "Avg time for hour to appear: " . $avg ."\n";
} else {
$avg = "-";
}

return $avg;
} // end function getHourAverage

function secondsToHours($secondsDifference) {
// Converts a number of seconds into a string formatted as H:i:s

return str_pad(floor($secondsDifference/3600), 2, "0", STR_PAD_LEFT) . ":" .
str_pad(floor(($secondsDifference % 3600)/60), 2, "0", STR_PAD_LEFT) . ":" .
str_pad(($secondsDifference % 60), 2, "0", STR_PAD_LEFT);
}

function DownloadCriteriaReport($AdWordsUser, $filePath, $dateRangeMin, $dateRangeMax){
// Downloads an account performance report from AdWords

try {

$reportQuery = 'SELECT Date, HourOfDay, Impressions, Clicks FROM ACCOUNT_PERFORMANCE_REPORT DURING ' . $dateRangeMin .",". $dateRangeMax;

$dateRange = array(
'min' => $dateRangeMin,
'max' => $dateRangeMax
);

$options = array('version' => ADWORDS_VERSION);

$options['skipReportHeader'] = true;
$options['skipReportSummary'] = false;

ReportUtils::DownloadReportWithAwql($reportQuery, $filePath, $AdWordsUser,
'CSV', $options);

echo "Report was downloaded. \n";

return true;
} catch (Exception $e) {
printf("An error has occurred: %s\n", $e->getMessage());
return false;
}
} // end function DownloadCriteriaReport

?>
