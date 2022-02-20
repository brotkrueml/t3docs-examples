<?php
namespace T3docs\Examples\Controller;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Controller for the backend module
 *
 * @author Francois Suter (Cobweb) <francois.suter@typo3.org>
 * @package TYPO3
 * @subpackage tx_examples
 */
class AdminModuleController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected array $exampleConfig = [];

    /**
     * The module menu items array.
     */
    protected array $MOD_MENU = [];

    /**
     * Current settings for the keys of the MOD_MENU array.
     */
    protected array $MOD_SETTINGS = [];

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly IconFactory $iconFactory,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly PasswordHashFactory $passwordHashFactory,
        protected readonly \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder,
        protected readonly FlashMessageService $flashMessageService,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $languageService->includeLLFile('EXT:examples/Resources/Private/Language/AdminModule/locallang.xlf');

        $this->menuConfig($request);
        $moduleTemplate = $this->moduleTemplateFactory->create($request, 't3docs/examples');
        $this->setUpDocHeader($moduleTemplate);

        $title = $languageService->sL('LLL:EXT:examples/Resources/Private/Language/AdminModule/locallang_mod.xlf:mlang_tabs_tab');
        switch ($this->MOD_SETTINGS['function']) {
            case 'debug':
                $moduleTemplate->setTitle($title, $languageService->getLL('module.menu.debug'));
                return $this->debugAction($moduleTemplate, $request);
            case 'password':
                $moduleTemplate->setTitle($title, $languageService->getLL('module.menu.password'));
                return $this->passwordAction($moduleTemplate, $request);
            default:
                $moduleTemplate->setTitle($title, $languageService->getLL('module.menu.log'));
                return $this->logAction($moduleTemplate);
        }
    }

    /**
     * Configure menu
     */
    protected function menuConfig(ServerRequestInterface $request): void
    {
        $lang = $this->getLanguageService();
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $this->MOD_MENU = [
            'function' => [
                0 => htmlspecialchars($lang->getLL('module.menu.log')),
                'debug' => htmlspecialchars($lang->getLL('module.menu.debug')),
                'password' => htmlspecialchars($lang->getLL('module.menu.password')),
            ],
        ];
        // CLEAN SETTINGS
        $this->MOD_SETTINGS = BackendUtility::getModuleData($this->MOD_MENU, $parsedBody['SET'] ?? $queryParams['SET'] ?? [], 'admin_examples', 'ses');
    }

    private function setUpShortcutButton(ModuleTemplate $moduleTemplate): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $shortCutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('admin_examples')
            ->setDisplayName($this->MOD_MENU['function'][$this->MOD_SETTINGS['function']])
            ->setArguments([
                'SET' => [
                    'function' => $this->MOD_SETTINGS['function'] ?? ''
                ],
            ]);
        $buttonBar->addButton($shortCutButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
    }
    /**
     * Generate doc header drop-down and shortcut button.
     */
    protected function setUpDocHeader(ModuleTemplate $moduleTemplate): void
    {
        $this->setUpShortcutButton($moduleTemplate);
        $menu = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('AdminExampleJumpMenu');
        foreach ($this->MOD_MENU['function'] as $controller => $title) {
            $item = $menu
                ->makeMenuItem()
                ->setHref(
                    (string)$this->uriBuilder->buildUriFromRoute(
                        'admin_examples',
                        [
                            'id' => 0,
                            'SET' => [
                                'function' => $controller,
                            ],
                        ]
                    )
                )
                ->setTitle($title);
            if ($controller === $this->MOD_SETTINGS['function']) {
                $item->setActive(true);
            }
            $menu->addMenuItem($item);
        }
        $moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * Creates some entries using the logging API
     * $this->logger gets set by usage of the LoggerAwareTrait
     *
     */
    public function logAction(ModuleTemplate $view): ResponseInterface
    {
        $this->logger->info('Everything went fine.');
        $this->logger->warning('Something went awry, check your configuration!');
        $this->logger->error(
            'This was not a good idea',
            [
                'foo' => 'bar',
                'bar' => $this,
            ]
        );
        $this->logger->log(
            LogLevel::CRITICAL,
            'This is an utter failure!'
        );
        $message = new FlashMessage(
            '3 log entries created',
            '',
            FlashMessage::INFO,
            true
        );
        $messageQueue = $this->flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->addMessage($message);
        return $view->renderResponse('AdminModule/Log');
    }

    /**
     * Displays the content of $_COOKIE
     *
     */
    public function debugAction(
        ModuleTemplate $view,
        ServerRequestInterface $request
    ): ResponseInterface
    {
        $cmd = $_POST['tx_examples_admin_examples']['cmd'];
        switch ($cmd) {
            case 'cookies':
                $this->debugCookies();
                break;
        }

        $view->assignMultiple(
            [
                'cookies' => $_COOKIE,
                'lastcommand' => $cmd,
            ]
        );
        return $view->renderResponse('AdminModule/Debug');
    }

    protected function debugCookies() {
        DebugUtility::debug($_COOKIE, 'cookie');
    }


    public function getPasswordHash(string $password, string $mode) : string {
        $hashInstance = $this->passwordHashFactory->getDefaultHashInstance($mode);
        return $hashInstance->getHashedPassword($password);
    }

    public function checkPassword(string $hashedPassword, string $expectedPassword, string $mode) : bool {
        $hashInstance = $this->passwordHashFactory->getDefaultHashInstance($mode);
        return $hashInstance->checkPassword($expectedPassword, $hashedPassword);
    }

    /**
     * checks or compares the password
     */
    public function passwordAction(ModuleTemplate $view, ServerRequestInterface $request): ResponseInterface
    {
        $passwordAction = 'get';
        $password = 'joh316';
        $hashedPassword = '';
        $mode = 'FE';
        $modes = ['FE' => 'FE', 'BE' => 'BE'];
        if ($passwordAction == 'Check') {
            $success = $this->checkPassword($hashedPassword, $password, $mode);
        } else {
            $hashedPassword = $this->getPasswordHash($password, $mode);
            $success = true;
        }
        $view->assignMultiple(
            [
                'modes' => $modes,
                'mode' => $mode,
                'hashedPassword' => $hashedPassword,
                'password' => $password,
                'success' => $success,
                'passwordAction' => $passwordAction
            ]
        );
        return $view->renderResponse('AdminModule/Password');
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
