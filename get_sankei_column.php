#!/usr/local/bin/php -q
<?php
define("FILE_PATH", $_SERVER["HOME"] . "/Desktop/sankei/");
define("FONT_PATH", "/System/Library/Fonts/ヒラギノ角ゴシック W3.ttc");

define("BORDER_WIDTH", 3);
define("PAGE_WIDTH", 1300 - BORDER_WIDTH * 2);
define("PAGE_HEIGHT", 2180 - BORDER_WIDTH * 2);

define("MARGIN_WIDTH", 50);
define("MARGIN_HEIGHT", 100);
define("LINE_HEIGHT", 1.2);

// get index page and extract the urls of each columns
function extract_clm_urls(){
	$idx = file("https://www.sankei.com/column/newslist/editorial-n1.html");
	if($idx == false){
		exit("Couldn't download the index page you specified.\n");
	}

	$urls = array();
	foreach($idx as $ln){
		if(preg_match("/a href=\"(https:\/\/www\.sankei\.com\/column\/news\/[0-9]+\/clm[0-9]+\-n1\.html)\">[^<]+</", $ln, $match)){
			$urls[]=$match[1];
		}
	}
	if(count($urls) == 0){
		exit("Couldn't find the urls to link to each columns on the index page.\n");
	}
	return $urls;
}

// decide which url to be extracted
function decide_clm_url($clm_urls){
	if($_SERVER["argc"] == 1){
		return $clm_urls[0];
	} else {
		$req_date = get_req_date($_SERVER["argv"][1]);
		if($req_date == false){
			exit("Please specify a date with 'yyyymmdd' format.\n");
		}
		foreach($clm_urls as $u){
			if(preg_match("/clm" . $req_date . "/", $u)){
				$clm_url = $u;
				break;
			}
		}
		if(isset($clm_url) == false){
			exit("Could not find the column with the date you specified.\n");
		}
		return $clm_url;
	}
}

// format the date
function get_req_date($arg){
	if(preg_match("/^[0-9]{4}[0-9]{2}[0-9]{2}$/", $arg)){
		return substr($arg, 2, 6);
	}
}

// extract title, date, and body from the page
function get_column($clm_url){
	$clm = file($clm_url);
	if($clm == false){
		exit(sprintf("Couldn't download the column page, '%s'.\n", $clm_url));
	}

	$is_in_body = false;
	$body = "";
	foreach($clm as $ln){
		if(preg_match("/<span id=\"__r_publish_date__\">([0-9 \.:]+)<\/span>/", $ln, $match)){
			$date_time=$match[1];
		}
		if(preg_match("/<span id=\"__r_article_title__\" class=\"pis_title\">([^<]+)<\/span>/", $ln, $match)){
			$title=$match[1];
		}
		if(preg_match("/<!-- article -->/", $ln, $match)){
			$is_in_body=true;
		}
		if(preg_match("/<!-- article end -->/", $ln, $match)){
			break(1);
		}
		if($is_in_body){
			if(preg_match("/<p>([^<]+)<\/p>/", $ln, $match)){
				$body .= $match[1] . "\n";
			}
		}
	}
	return array("title"=>$title, "date_time"=>$date_time, "body"=>$body);
}

function newImage(){
	$im = new Imagick();
	$im->newImage(PAGE_WIDTH, PAGE_HEIGHT, new ImagickPixel("white"));
	$im->setImageUnits(1);
	$im->setImageResolution(254, 254);
	return $im;
}

function newDraw(){
	$draw = new ImagickDraw();
	try {
		$draw->setFont(FONT_PATH);
	} catch (Exception $e) {
		echo $e->getMessage() . "\n";
	}
	return $draw;
}

function drawColumn($clm){
	$im = newImage();
	$draw = newDraw();

	$page_no = 1;
	// Draw Title
	$titleFontSize = 72;
	$draw->setFontSize($titleFontSize);
	$wrappedTitle = imWrapText($clm["title"], $im, $draw);
	$baseY = MARGIN_HEIGHT;
	imDrawAnnotation($draw, $wrappedTitle, $titleFontSize, $baseY);

	// Draw DateTime
	$dateTimeFontSize = 24;
	$draw->setFontSize($dateTimeFontSize);
	$wrappedDateTime = imWrapText($clm["date_time"], $im, $draw);
	$baseY += count($wrappedTitle) * $titleFontSize * LINE_HEIGHT;
	imDrawAnnotation($draw, $wrappedDateTime, $dateTimeFontSize, $baseY);

	// Draw Body
	$bodyFontSize = 32;
	$draw->setFontSize($bodyFontSize);
	$wrappedBody = imWrapText($clm["body"], $im, $draw);
	$baseY += count($wrappedDateTime) * $dateTimeFontSize * LINE_HEIGHT + 50;
	$line_drawn = imDrawAnnotation($draw, $wrappedBody, $bodyFontSize, $baseY);
	while($line_drawn > 0){
		save_image($im, $draw, $baseY + $line_drawn * $bodyFontSize * LINE_HEIGHT, $clm["date_time"], $page_no);
		$im = newImage();
		$draw = newDraw();
		$draw->setFontSize($bodyFontSize);
		$wrappedBody = array_slice($wrappedBody, $line_drawn);
		$baseY = MARGIN_HEIGHT;
		$line_drawn = imDrawAnnotation($draw, $wrappedBody, $bodyFontSize, $baseY);
	}
	save_image($im, $draw, $baseY + count($wrappedBody) * $bodyFontSize * LINE_HEIGHT - 50, $clm["date_time"], $page_no);
}

function save_image($im, $draw, $page_height, $date_time, &$page_no){
	$im->cropImage($im->getImageWidth(), $page_height, 0, 0);
	$im->borderImage(new ImagickPixel("black"), BORDER_WIDTH, BORDER_WIDTH);
	$im->drawImage($draw);
	$im->setImageFormat("png");
	list($date, $time) = explode(" ", $date_time);
	list($year, $month, $day) = explode(".", $date);
	$fname = FILE_PATH . sprintf("%02d.%02d.%02d-%02d", $year, $month, $day, $page_no) . ".png";
	$im->writeImage($fname);
	$im->clear();
	$im->destroy();
	$page_no++;
}

function imDrawAnnotation(&$draw, $wrappedText, $fontSize, $baseY){
	foreach ($wrappedText as $_i => $_s) {
		$_y = $baseY + $fontSize * LINE_HEIGHT * $_i;
		if($_y > PAGE_HEIGHT - MARGIN_HEIGHT){
			return $_i;
		}
		$draw->annotation(MARGIN_WIDTH, $_y, $_s);
	}
}

function imWrapText($text, $im, &$draw){
	$imWidth = $im->getImageWidth() - MARGIN_WIDTH * 2;
	$wrappedText = array();
	$metrics = "";
	$s = "";
	$txt_ln = mb_strlen($text);
	for($i = 0; $i < $txt_ln; $i++){
		$a = mb_substr($text, $i, 1);
		if(strcmp($a, "\n") != 0){
			$metrics = $im->queryFontMetrics($draw, $s . $a);
			if(isset($metrics["textWidth"]) && $metrics["textWidth"] > $imWidth){
				if(mb_ereg("[)\]}｣ﾞﾟ゛゜’”）〕］｝〉》」』】°′″,.｡､、。，．]", $a) == false){
					$wrappedText[] = $s;
					$s = $a;
				} else {
					$s .= $a;
				}
			} else {
				$s .= $a;
			}
		} else {
			$wrappedText[] = $s;
			$wrappedText[] = "";
			$s = "";
		}
	}
	$wrappedText[] = $s;
	return $wrappedText;
}

///// MAIN
$clm_urls = extract_clm_urls();
$clm_url = decide_clm_url($clm_urls);
$clm = get_column($clm_url);
drawColumn($clm);
?>
