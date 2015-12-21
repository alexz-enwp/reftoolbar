<?php
/*
	Copyright 2013 Alex Zaddach. (mrzmanwiki@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

/*
	This is an API for retrieving bibliographic data using a PMID, ISBN, DOI, or URL.
	Example usage: lookup.php?isbn=9781848449510&template=book
*/

header('Content-type: text/javascript');
class PMIDLookup {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function getResult() {
		$url = 'http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?';
		$url .= "&db=pubmed";
		$url .= '&tool=WikipediaRefToolbar2';
		$url .= '&email=mrzmanwiki@gmail.com';
		$url .= "&id={$this->id}";
		$url .= '&retmode=xml';

		$months = array('Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
				'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
				'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12');
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$xml = curl_exec($ch);
		curl_close($ch);
		$data = simplexml_load_string($xml);
		$result = array();
		foreach($data->DocSum->Item as $i) {
			switch ($i['Name']) {
				case 'PubDate':
					$result['fulldate'] = true;
					$date = explode (" ", (string)$i);
					if (!isset($date[2])) {
						$result['fulldate'] = false;
						$result['year'] = $date[0];
						if (isset($date[1])) {
							$result['month'] = $months[$date[1]];
						} else {
							$result['month'] = false;
						}
					}
					$result['date'] = $date[0];
					if (isset($date[1])) {
						$result['date'].='-'.$months[$date[1]];
						if (isset($date[2])) {
							$result['date'].='-'.str_pad($date[2], 2, "0", STR_PAD_LEFT);
						}
					}
					break;
				case 'FullJournalName':
					$result['journal'] = (string)$i;
					break;
				case 'Title':
					$result['title'] = (string)$i;
					break;
				case 'Volume':
					$result['volume'] = (string)$i;
					break;
				case 'Issue':
					$result['issue'] = (string)$i;
					break;
				case 'Pages':
					$result['pages'] = (string)$i;
					break;
				case 'AuthorList':
					foreach($i->Item as $a) {
						$r = preg_match('/^(.*?) (\S*)$/', (string)$a, $match);
						if ($r) {
							$result['authors'][] = array( $match[1], $match[2] );
						} else {
							$result['authors'][] = array( (string)$a, '' );
						}
					}
					break;
			}
		}
		return $result;
	}
}

class ISBNLookup {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function getResult() {
		$url = "http://xisbn.worldcat.org/webservices/xid/isbn/{$this->id}?method=getMetadata&format=json&fl=year,ed,title,author,publisher,city";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$json = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($json, true);
		$result = array();
		if ($data['stat'] == 'ok') {
			$result['title'] = $data['list'][0]['title'];
			$result['publisher'] = $data['list'][0]['publisher'];
			$result['location'] = $data['list'][0]['city'];
			$result['year'] = $data['list'][0]['year'];
			$result['edition'] = $data['list'][0]['ed'];
			$authors = $data['list'][0]['author'];
			$authors = rtrim($authors, '.');
			if (strpos($authors, 'by ') === 0) {
				$authors = substr($authors, 3);
			}
			$result['authors'] = array();
			$a = explode(' and ', $authors);
			$alist = array();
			if (count($a) == 2) {
				$alist = explode(', ', $a[0]);
				$alist[] = $a[1];
			} else {
				$alist[] = $authors;
			}
			foreach($alist as $a) {
				$r = preg_match('/^(.*?) (\S*)$/', $a, $match);
				if ($r) {
					$result['authors'][] = array( $match[2], $match[1] );
				} else {
					$result['authors'][] = array( $a, '');
				}
			}
		}
		return $result;
	}

}

class DOILookup {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function getResult() {
		require_once('crossref.php'); // username for crossref openurl system
		$url = "http://www.crossref.org/openurl/?id={$this->id}&noredirect=true&pid=$crPID&format=unixref";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$xml = curl_exec($ch);
		curl_close($ch);
		$data = simplexml_load_string($xml);
		$result = array();
		if (!$data->doi_record->crossref->error) {
			$res = $data->doi_record->crossref->journal;

			$result['title'] = (string)$res->journal_article->titles->title[0];
			$result['journal'] = (string)$res->journal_metadata->full_title;
			$result['volume'] = (string)$res->journal_issue->journal_volume->volume;
			$result['issue'] = (string)$res->journal_issue->issue;
			if ($res->journal_article->pages) {
				$result['pages'] = (string)$res->journal_article->pages->first_page;
				$res->journal_article->pages->last_page ? $result['pages'].='–'.$res->journal_article->pages->last_page : 0;
			}
			$result['fulldate'] = true;
			if (!$res->journal_article->publication_date->day) {
				$result['fulldate'] = false;
				$result['year'] = (string)$res->journal_article->publication_date->year;
				if ($res->journal_article->publication_date->month) {
					$result['month'] = (string)$res->journal_article->publication_date->month;
				} else {
					$result['month'] = false;
				}
			}
			$result['date'] = (string)$res->journal_article->publication_date->year;
			if ($res->journal_article->publication_date->month) {
				(string)$result['date'].='-'.str_pad( $res->journal_article->publication_date->month, 2, "0", STR_PAD_LEFT);
				if ( $res->journal_article->publication_date->day ) {
					(string)$result['date'].='-'.str_pad($res->journal_article->publication_date->day, 2, "0", STR_PAD_LEFT);
				}
			}
			$result['authors'] = array();

			foreach($res->journal_article->contributors->person_name as $a) {
				$result['authors'][] = array((string)$a->surname, (string)$a->given_name);
			}
		}
		return $result;
	}
}

class URLLookup {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function getResult() {
		// See https://www.mediawiki.org/wiki/Citoid/API
		$url = "http://citoid.wikimedia.org/api?basefields=true&format=mediawiki&search={$this->id}";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$json = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($json, true);
		$result = array();
		if ( $data ) {
			$itemType = $data[0]['itemType'];
			foreach ( $data[0] as $key => $value ) {
				switch ( $key ) {
					case 'publicationTitle':
						if ( $itemType === 'bookSection' && $_GET['template'] === 'book' ) {
							$result['title'] = $value;
						} else {
							$result['journal'] = $value;
						}
						break;
					case 'title':
						if ( $itemType === 'bookSection' && $_GET['template'] === 'book' ) {
							$result['chapter'] = $value;
						} else {
							$result['title'] = $value;
						}
						break;
					case 'websiteTitle':
						// RefToolbar maps all work titles to journal
						$result['journal'] = $value;
						break;
					case 'volume':
						$result['volume'] = $value;
						break;
					case 'issue':
						$result['issue'] = $value;
						break;
					case 'edition':
						// Citoid API returns 'X edition', but templates expect just 'X'
						$result['edition'] = str_replace(' edition','',$value);
						break;
					case 'publisher':
						$result['publisher'] = $value;
						break;
					case 'pages':
						$result['pages'] = $value;
						break;
					case 'ISBN':
						$result['isbn'] = $value[0];
						break;
					case 'ISSN':
						$result['issn'] = $value[0];
						break;
					case 'date':
						$result['date'] = $value;
						break;
					case 'DOI':
						$result['doi'] = $value;
						break;
					case 'language':
						$result['language'] = $value;
						break;
					case 'author':
						foreach( $value as $author ) {
							if ( $author[0] && $author[1] ) {
								// RefToolbar gadget expects lastName, firstName
								$result['authors'][] = array($author[1], $author[0]);
							}
						}
				}
			}
		}
		return $result;
	}

}

$k = array_keys($_GET);
if (!isset( $k[0])) {
	die(1);
}
switch($k[0]) {
	case 'pmid':
		$class = 'PMIDLookup';
		break;
	case 'isbn':
		$class = 'ISBNLookup';
		break;
	case 'doi':
		$class = 'DOILookup';
		break;
	case 'url':
		$class = 'URLLookup';
		break;
	default:
		die(1);
}
$idval = trim($_GET[$k[0]]);
$look = new $class($idval);
if (file_exists( '/data/project/reftoolbar/log.txt')) {
	$log = file_get_contents( '/data/project/reftoolbar/log.txt' );
	$log = json_decode($log, true);
	$logdate = date('Y-m-d');
	if (isset($log[$k[0]][$logdate])) {
		$log[$k[0]][$logdate]++;
	} else {
		$log[$k[0]][$logdate] = 1;
	}
	$log = json_encode($log);
	file_put_contents('/data/project/reftoolbar/log.txt', $log);
}
$res = $look->getResult();
$tem = $_GET['template'];
echo 'CiteTB.autoFill('.json_encode($res).", '$tem', '{$k[0]}')";
