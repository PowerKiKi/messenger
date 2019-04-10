<?php

namespace Fab\Messenger\PagePath;

/*
 * This file is part of the Fab/Messenger project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use Fab\Messenger\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;

/**
 * This class create frontend page address from the page id value and parameters.
 *
 * @author Dmitry Dulepov <dmitry@typo3.org>
 */
class PagePath
{

    /**
     * Creates URL to page using page id and parameters
     *
     * @param int $pageId
     * @param mixed $parameters
     * @return  string
     */
    public static function getUrl($pageId, $parameters): string
    {
        if (is_array($parameters)) {
            $parameters = GeneralUtility::implodeArrayForUrl('', $parameters);
        }
        $data = array(
            'id' => (int)$pageId,
        );
        if ($parameters !== '' && $parameters{0} === '&') {
            $data['parameters'] = $parameters;
        }
        $siteUrl = self::getSiteBaseUrl($pageId);

        if ($siteUrl) {
            $url = $siteUrl . 'index.php?eID=messenger&data=' . base64_encode(serialize($data));

            // Send TYPO3 cookies as this may affect path generation
            $headers = array(
                'Cookie: fe_typo_user=' . $_COOKIE['fe_typo_user']
            );
            $result = GeneralUtility::getUrl($url, false, $headers);

            $urlParts = parse_url($result);
            if (!is_array($urlParts)) {

                // filter_var is too strict (for example, underscore characters make it fail). So we use parse_url here for a quick check.
                $result = '';
            } elseif ($result) {

                // See if we need to prepend domain part
                if (!isset($urlParts['host']) || $urlParts['host'] === '') {
                    $result = rtrim($siteUrl, '/') . '/' . ltrim($result, '/');
                }
            }

        } else {
            $result = '';
        }
        return $result;
    }

    /**
     * Obtains site URL.
     *
     * @static
     * @param int $pageId
     * @return string
     * @throws \UnexpectedValueException
     */
    public static function getSiteBaseUrl($pageId): string
    {
        // CLI must define its own environment variable.
        if (TYPO3_REQUESTTYPE & TYPO3_REQUESTTYPE_CLI) {

            $environmentBaseUrl = getenv('TYPO3_BASE_URL');
            $baseUrl = rtrim($environmentBaseUrl, '/') . '/';
            if (!$baseUrl) {
                $message = 'ERROR in Messenger!' . chr(10);
                $message .= 'I can not send emails because of missing environment variable TYPO3_BASE_URL' . chr(10);
                $message .= 'You can set it when calling the CLI script as follows:' . chr(10) . chr(10);
                $message .= 'TYPO3_BASE_URL=http://www.domain.tld typo3/cli_dispatch.phpsh scheduler' . chr(10);
                die($message);
            }
        } else {
            $siteRootPage = [];
            $domainName = '';
            foreach (\TYPO3\CMS\Backend\Utility\BackendUtility::BEgetRootLine($pageId) as $page) {
                if ((int)$page['is_siteroot'] === 1) {
                    $siteRootPage = $page;
                }
            }
            if (!empty($siteRootPage)) {
                $domain = self::guessFistDomain($siteRootPage['uid']);
                if (!empty($domain)) {
                    $domainName = $domain['domainName'];

                }
            }
            $baseUrl = $domainName
                ? self::getScheme($siteRootPage['uid']) . '://' . $domainName . '/'
                : GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
        }

        return $baseUrl;
    }

    /**
     * @param int $pageId
     * @return array
     */
    protected static function getScheme($pageId): string
    {
        $pageRecord = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('pages', $pageId);
        return is_array($pageRecord) && isset($pageRecord['url_scheme']) && $pageRecord['url_scheme'] === HttpUtility::SCHEME_HTTPS
            ? 'https'
            : 'http';
    }

    /**
     * @param int $pageId
     * @return array
     */
    protected static function guessFistDomain(int $pageId): array
    {
        /** @var QueryBuilder $query */
        $queryBuilder = self::getQueryBuilder('sys_domain');
        $queryBuilder->select('*')
            ->from('sys_domain')
            ->andWhere(
                'pid = ' . $pageId
            )
            ->addOrderBy('sorting', 'ASC');

        $record = $queryBuilder
            ->execute()
            ->fetch();
        return is_array($record)
            ? $record
            : [];
    }

    /**
     * @param string $tableName
     * @return object|QueryBuilder
     */
    protected static function getQueryBuilder($tableName): QueryBuilder
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        return $connectionPool->getQueryBuilderForTable($tableName);
    }

}
