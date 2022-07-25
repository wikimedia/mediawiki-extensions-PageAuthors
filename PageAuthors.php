<?php

use MediaWiki\MediaWikiServices;

class PageAuthors {

	/**
	 * Register PAGEAUTHORS variable
	 *
	 * @param string[] &$variableIDs
	 */
	public static function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'PAGEAUTHORS';
	}

	/**
	 * Register PAGEAUTHORS parser function
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'PAGEAUTHORS', [ self::class, 'getPageAuthors' ], SFH_NO_HASH );
	}

	/**
	 * Assign a value to PAGEAUTHORS variable
	 *
	 * @param Parser $parser
	 * @param array &$variableCache
	 * @param string $magicWordId
	 * @param string &$ret
	 * @param PPFrame $frame
	 * @return bool
	 */
	public static function onParserGetVariableValueSwitch( $parser, &$variableCache, $magicWordId, &$ret, $frame ) {
		if ( $magicWordId === 'PAGEAUTHORS' ) {
			$ret = self::getPageAuthors( $parser );
		}
		return true;
	}

	/**
	 * Get the main authors of the given page
	 *
	 * @param Parser $parser
	 * @param string $input
	 * @return string
	 */
	public static function getPageAuthors( Parser $parser, string $input = '' ) {
		global $wgPageAuthorsDelimiter,
			$wgPageAuthorsMinBytesPerEdit,
			$wgPageAuthorsMinBytesPerAuthor,
			$wgPageAuthorsIgnoreSummaryPatterns,
			$wgPageAuthorsIgnoreMinorEdits,
			$wgPageAuthorsIgnoreSystemUsers,
			$wgPageAuthorsIgnoreBots,
			$wgPageAuthorsIgnoreBlocked,
			$wgPageAuthorsIgnoreAnons,
			$wgPageAuthorsIgnoreUsers,
			$wgPageAuthorsIgnoreGroups;
		$title = $input ? Title::newFromText( $input ) : $parser->getTitle();
		$id = $title->getArticleID();
		$authors = [];
		$dbr = wfGetDB( DB_REPLICA );
		$revisionSize = 0;
		$revisionIds = $dbr->selectFieldValues( 'revision', 'rev_id', 'rev_page = ' . $id );
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		foreach ( $revisionIds as $revisionId ) {
			$revision = $revisionStore->getRevisionById( $revisionId );
			if ( !$revision ) {
				continue;
			}
			$revisionDiff = $revision->getSize() - $revisionSize;
			$revisionSize = $revision->getSize();
			if ( $revisionDiff < $wgPageAuthorsMinBytesPerEdit ) {
				continue;
			}
			if ( $wgPageAuthorsIgnoreMinorEdits && $revision->isMinor() ) {
				continue;
			}
			if ( $wgPageAuthorsIgnoreSummaryPatterns ) {
				$comment = $revision->getComment();
				$summary = $comment->text;
				foreach ( $wgPageAuthorsIgnoreSummaryPatterns as $pattern ) {
					if ( preg_match( $pattern, $summary ) ) {
						continue 2;
					}
				}
			}
			$revisionUser = $userFactory->newFromUserIdentity( $revision->getUser() );
			if ( $wgPageAuthorsIgnoreSystemUsers && $revisionUser->isSystemUser() ) {
				continue;
			}
			if ( $wgPageAuthorsIgnoreBots && $revisionUser->isBot() ) {
				continue;
			}
			if ( $wgPageAuthorsIgnoreBlocked && $revisionUser->isBlocked() ) {
				continue;
			}
			if ( $wgPageAuthorsIgnoreAnons && $revisionUser->isAnon() ) {
				continue;
			}
			if ( $wgPageAuthorsIgnoreGroups && array_intersect( $revisionUser->getGroups(), $wgPageAuthorsIgnoreGroups ) ) {
				continue;
			}
			$revisionUserName = $revisionUser->getName();
			if ( $wgPageAuthorsIgnoreUsers && in_array( $revisionUserName, $wgPageAuthorsIgnoreUsers ) ) {
				continue;
			}
			if ( array_key_exists( $revisionUserName, $authors ) ) {
				$authors[ $revisionUserName ] += $revisionDiff;
			} else {
				$authors[ $revisionUserName ] = $revisionDiff;
			}
		}
		$authors = array_filter( $authors, static function ( $bytes ) {
			global $wgPageAuthorsMinBytesPerAuthor;
			return $wgPageAuthorsMinBytesPerAuthor < $bytes;
		} );
		arsort( $authors );
		$authors = array_keys( $authors );
		$authors = implode( $wgPageAuthorsDelimiter, $authors );
		return $authors;
	}
}
