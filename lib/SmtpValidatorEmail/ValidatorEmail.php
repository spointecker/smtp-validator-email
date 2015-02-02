<?php
/**
 * Created by JetBrains PhpStorm.
 * User: daviddjian
 * Date: 31/07/13
 * Time: 19:20
 * To change this template use File | Settings | File Templates.
 */

namespace SmtpValidatorEmail;

use SmtpValidatorEmail\Domain\Domain;
use SmtpValidatorEmail\Email\Email;
use SmtpValidatorEmail\Exception\ExceptionNoConnection;
use SmtpValidatorEmail\Helper\BagHelper;
use SmtpValidatorEmail\Helper\EmailHelper;
use SmtpValidatorEmail\Helper\SmtpTransportHelper;
use SmtpValidatorEmail\Mx\Mx;
use SmtpValidatorEmail\Smtp\Smtp;
use Symfony\Component\Config\Definition\Exception\Exception;


/**
 * Class ValidatorEmail
 * @package SmtpValidatorEmail
 */
class ValidatorEmail
{
    /**
     * @var array
     */
    protected $domains;
    /**
     * @var
     */
    protected $fromUser = 'user';
    /**
     * @var
     */
    protected $fromDomain = 'localhost';

    /**
     * @var array
     */
    protected $results = array();

    /**
     * @var array
     */
    protected $options = array();

    /**
     * Constructs the validator
     *
     * @param array|string $emails
     * @param string $sender
     * @param array $options
     *
     * possible options :
     *
     *      'domainMoreInfo' (bool) for have more information on domains of users.
     *      'delaySleep' is an array of delay(s) possible after connected Server to send the request SMTP.
     *      'catchAllIsValid' (int) can be 0 for false or 1 for true . Are 'catch-all' accounts considered valid or not?
     *      'catchAllEnabled' (int) 0 off 1 on enables catchAll test , may take more time
     *      'noCommIsValid' Being unable to communicate with the remote MTA could mean an address
     *                      is invalid, but it might not, depending on your use case, set the
     *                      value appropriately.
     */
    public function __construct($emails = array(), $sender, $options = array())
    {
        $defaultOptions = array(
            'domainMoreInfo' => false,
            'delaySleep' => array(0),
            'noCommIsValid' => 0,
            'catchAllIsValid' => 1,
            'catchAllEnabled' => 1,
            'sameDomainLimit' => 3,
            'logPath' => null
        );

        $emails = is_array($emails) ? EmailHelper::sortEmailsByDomain($emails) : $emails;

        $this->options = array_merge($defaultOptions,$options);
        $this->setSender($sender);
        $this->setBags($emails);
    }

    /**
     * @param array|string $emails
     */
    public function setBags($emails){
        if (!empty($emails)) {
            $emailBag = new BagHelper();
            $emailBag->add((array)$emails);
            $domainBag = $this->setEmailsDomains($emailBag);
            $this->domains = $domainBag->all();
        }
    }

    /**
     * Returns results
     * @return array
     */
    public function getResults()
    {
        if(!$this->results){
            $this->runValidation($this->options);
        }
        return $this->results;
    }

    /**
     * Sets the email addresses that should be validated.
     *
     * @param BagHelper $emailBag
     *
     * @return BagHelper $domainBag
     */
    public function setEmailsDomains(BagHelper $emailBag)
    {
        $domainBag = new BagHelper();

        foreach ($emailBag as $key => $emails) {
            foreach ($emails as $email) {

                $mail = new Email($email);

                list($user, $domain) = $mail->parse();

                $domainBag->set($domain, $user, false);

            }
        }

        return $domainBag;
    }

    /**
     * Sets the email address to use as the sender/validator.
     *
     * @param string $email
     *
     * @return void
     */
    public function setSender($email)
    {
        $mail = new Email($email);
        $parts = $mail->parse();

        $this->fromUser = $parts[0];
        $this->fromDomain = $parts[1];
    }

    /**
     * Helper to set results for all the users on a domain to a specific value
     *
     * @param array $users    Array of users (usernames)
     * @param Domain $domain   The domain
     * @param int $val      Value to set
     * @param String $info  Optional , can be used to give additional information about the result
     */
    private function setDomainResults($users, Domain $domain, $val, $info='')
    {
        if (!is_array($users)) {
            $users = (array)$users;
        }

        foreach ($users as $user) {
            $this->results[$user . '@' . $domain->getDomain()] = array(
                'result' => $val,
                'info'   => $info
            );

        }
    }

    /**
     * @param $options array
     * @throws Exception\ExceptionNoHelo
     * @throws Exception\ExceptionNoMailFrom
     * @throws Exception\ExceptionNoTimeout
     */
    private function runValidation($options){

        // The foreach fires for each email in array
        foreach ($this->domains as $domain => $users) {

            $mx = new Mx();
            $mxs = $mx->getEntries($domain);

            $dom = new Domain($domain);
            $dom->addDescription(array('users' => $users));
            $dom->addDescription(array('mxs' => $mxs));

            $count = count($options['delaySleep']);
            $i = 0;
            $loopStop = 0;
            while ($i < $count && $loopStop != 1) {

                // $smtp = new Smtp(array('fromDomain' => $this->fromDomain, 'fromUser' => $this->fromUser, 'logPath' => $options['logPath'] ));
                $transport = new SmtpTransportHelper(array('fromDomain' => $this->fromDomain, 'fromUser' => $this->fromUser, 'logPath' => $options['logPath'] ));
                $smtp = $transport->getSmtp();

                if (array_key_exists('timeout', $options)) {
                    $smtp->setTimeout($options['timeout']);
                }

                // try connecting to the remote host
//                if( $result = $transport->connect($mxs) ) {
//                    $this->setDomainResults($users, $dom, 0,$result);
//                }

                try {
                    $result = $transport->connect($mxs);
                    $this->setDomainResults($users, $dom, 0,$result);
                } catch (Exception $e) {
                    dump('could not connect to host '.$mxs);
                }

                // are we connected?
                if ($smtp->isConnect()) {

                    // TODO : Make a dynamic sleep timeout
                    sleep($options['delaySleep'][$i]);

                    // say helo, and continue if we can talk
                    if ($smtp->helo()) {

                        // try issuing MAIL FROM
                        if (!($smtp->mail($this->fromUser . '@' . $this->fromDomain))) {
                            // MAIL FROM not accepted, we can't talk
                            $this->setDomainResults($users, $dom, $options['noCommIsValid'],'MAIL FROM not accepted');
                        }

                        /**
                         * if we're still connected, proceed (cause we might get
                         * disconnected, or banned, or greylisted temporarily etc.)
                         * see mail() for more
                         */
                        if ( $smtp->isConnect()) {

                            $smtp->noop();

                            // Do a catch-all test for the domain .
                            // This increases checking time for a domain slightly,
                            // but doesn't confuse users.
                            if($options['catchAllEnabled']){
                                try{
                                    $isCatchallDomain = $smtp->acceptsAnyRecipient($dom);
                                }catch (\Exception $e) {
                                    $this->setDomainResults($users, $dom, $options['catchAllIsValid'], 'error while on CatchAll test: '.$e );
                                }
                                // if a catchall domain is detected, and we consider
                                // accounts on such domains as invalid, mark all the
                                // users as invalid and move on
                                if ($isCatchallDomain) {
                                    if (!$options['catchAllIsValid']) {
                                        $this->setDomainResults($users, $dom, $options['catchAllIsValid'],'catch all detected');
                                        continue;
                                    }
                                }
                            }

                            // if we're still connected, try issuing rcpts
                            if ($smtp->isConnect()) {
                                $smtp->noop();

                                // rcpt to for each user
                                foreach ($users as $user) {
                                    // TODO: An error from the SMTP couse a disconnect , need to implement a reconnect
                                    if(!$smtp->isConnect()){
                                        dump('Smtp not connected , trying reconnect');
                                        $transport->reconnect($this->fromUser . '@' . $this->fromDomain);
                                        dump($smtp->isConnect());
                                        if(!$smtp->isConnect()){
                                            die;
                                        }
                                    }
                                    $address = $user . '@' . $dom->getDomain();
                                    // Sets the results to an integer 0 ( failure ) or 1 ( success )
                                    $this->results[$address] = $smtp->rcpt($address);

                                    if ($this->results[$address] == 1) {
                                        $loopStop = 1;
                                    }

//                                    if( $userCounter >= $options["sameDomainLimit"] ){
//                                        //TODO: the dump should go to log
//                                        dump('Same domain limit reached, issuing  sleep for '.count($users).' sec');
//                                        sleep(count($users));
//                                        $userCounter = 0;
//                                    }
                                    $smtp->noop();
//                                    $userCounter++;
                                }
                            }

                            // saying buh-bye if we're still connected, cause we're done here
                            if ($smtp->isConnect()) {
                                // issue a rset for all the things we just made the MTA do
                                $smtp->rset();
                                // kiss it goodbye
                                $smtp->disconnect();
                            }

                        }

                    } else {
                        // we didn't get a good response to helo and should be disconnected already
                        $this->setDomainResults($users, $dom, $options['noCommIsValid'],'bad response on helo');
                    }
                } else {
                    $this->setDomainResults($users, $dom, 0,'no connection ');
                }

                if ($options['domainMoreInfo']) {
                    $this->results[$dom->getDomain()] = $dom->getDescription();
                }

                $i++;
            }
        }
    }
}
