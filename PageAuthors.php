<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

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
		$variableCache[ $magicWordId ] = $ret;
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
		$authors = [];

		// Set some variables that we'll need in the loop
		$services = MediaWikiServices::getInstance();
		$provider = $services->getConnectionProvider();
		$dbr = $provider->getReplicaDatabase();
		$revisionStore = $services->getRevisionStore();
		$userFactory = $services->getUserFactory();
		$userGroupManager = $services->getUserGroupManager();
		$config = $services->getMainConfig();

		$title = $input ? Title::newFromText( $input ) : $parser->getTitle();
		$revisionIds = $dbr->selectFieldValues( 'revision', 'rev_id', 'rev_page = ' . $title->getId() );
		$revisionSize = 0;
		foreach ( $revisionIds as $revisionId ) {
			$revision = $revisionStore->getRevisionById( $revisionId );
			if ( !$revision ) {
				continue;
			}
			$revisionDiff = $revision->getSize() - $revisionSize;
			$revisionSize = $revision->getSize();
			if ( $revisionDiff < $config->get( 'PageAuthorsMinBytesPerEdit' ) ) {
				continue;
			}
			if ( $config->get( 'PageAuthorsIgnoreMinorEdits' ) && $revision->isMinor() ) {
				continue;
			}
			$patterns = $config->get( 'PageAuthorsIgnoreSummaryPatterns' );
			$comment = $revision->getComment();
			$summary = $comment->text;
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $summary ) ) {
					continue;
				}
			}
			$revisionUser = $userFactory->newFromUserIdentity( $revision->getUser() );
			if ( $config->get( 'PageAuthorsIgnoreSystemUsers' ) && $revisionUser->isSystemUser() ) {
				continue;
			}
			if ( $config->get( 'PageAuthorsIgnoreBots' ) && $revisionUser->isBot() ) {
				continue;
			}
			if ( $config->get( 'PageAuthorsIgnoreBlocked' ) && $revisionUser->getBlock() ) {
				continue;
			}
			if ( $config->get( 'PageAuthorsIgnoreAnons' ) && $revisionUser->isAnon() ) {
				continue;
			}
			$userGroups = $userGroupManager->getUserGroups( $revisionUser );
			if ( array_intersect( $userGroups, $config->get( 'PageAuthorsIgnoreGroups' ) ) ) {
				continue;
			}
			$author = $revisionUser->getName();
			if ( in_array( $author, $config->get( 'PageAuthorsIgnoreUsers' ) ) ) {
				continue;
			}
			$userPage = $revisionUser->getUserPage()->getFullText();
			if ( $config->get( 'PageAuthorsShowUserNamespace' ) ) {
				$author = $userPage;
			}
			$realName = $revisionUser->getRealName();
			if ( $config->get( 'PageAuthorsUseRealNames' ) && $realName ) {
				$author = $realName;
			}
			if ( $config->get( 'PageAuthorsLinkUserPages' ) ) {
				$author = "[[$userPage|$author]]";
			}
			if ( array_key_exists( $author, $authors ) ) {
				$authors[ $author ] += $revisionDiff;
			} else {
				$authors[ $author ] = $revisionDiff;
			}
		}
		$authors = array_filter( $authors, static fn ( $bytes ) =>
			$config->get( 'PageAuthorsMinBytesPerAuthor' ) < $bytes
		);
		arsort( $authors );
		$authors = array_keys( $authors );
		$authors = implode( $config->get( 'PageAuthorsDelimiter' ), $authors );
		return $authors;
	}
}
