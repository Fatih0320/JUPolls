<?php
/**
 * JUPolls
 *
 * @package          Joomla.Site
 * @subpackage       com_mijopolls
 *
 * @author           Denys Nosov, denys@joomla-ua.org
 * @copyright        2016-2017 (C) Joomla! Ukraine, http://joomla-ua.org. All rights reserved.
 * @license          GNU General Public License version 2 or later; see LICENSE.txt
 * @license          GNU/GPL based on AcePolls www.joomace.net
 */

/**
 * @copyright      2009-2011 Mijosoft LLC, www.mijosoft.com
 * @license        GNU/GPL http://www.gnu.org/copyleft/gpl.html
 * @license        GNU/GPL based on AcePolls www.joomace.net
 *
 * @copyright (C)  2009 - 2011 Hristo Genev All rights reserved
 * @license        http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link           http://www.afactory.org
 */

defined('_JEXEC') or die('Restricted access');

class MijopollsViewPoll extends JViewLegacy
{
    function display($tpl = null)
    {
        $app = JFactory::getApplication();
        $poll_id = $app->input->getInt('id', 0);

        $poll = JTable::getInstance('Poll', 'Table');
        $poll->load($poll_id);

        if($poll->id > 0 && $poll->published != 1)
        {
            $app->enqueueMessage(JText::_('Access Forbidden'), 'error');

            return;
        }

        $db       = JFactory::getDBO();
        $user     = JFactory::getUser();
        $date     = JFactory::getDate();
        $doc = JFactory::getDocument();
        $pathway  = $app->getPathway();

        $now = $date->toSql();

        $model = $this->getModel('Poll');

        // Adds parameter handling
        $temp   = new JRegistry($poll->params);
        $params = clone($app->getParams());
        $params->merge($temp);

        $menu = JSite::getMenu()->getActive();
        if(is_object($menu))
        {
            $menu_params = new JRegistry($menu->params);
            if(!$menu_params->get('page_title'))
            {
                $params->set('page_title', $poll->title);
            }
            else
            {
                $params->set('page_title', $menu_params->get('page_title'));
            }
        }
        else
        {
            $params->set('page_title', $poll->title);
        }

        $poll_param = json_decode($poll->params);

        if($poll_param->description != '')
        {
            $poll_desc = str_replace("\r\n", ' ', $poll_param->description);
            $poll_desc = trim($poll_desc);
            $doc->setMetaData('description', $poll_desc, 'content');
        }

        $doc->setTitle($params->get('page_title'));

        //Set pathway information
        $pathway->addItem($poll->title, '');

        $params->def('show_page_title', 1);
        $params->def('page_title', $poll->title);

        // Check if there is a poll corresponding to id and if poll is published
        if($poll->id > 0)
        {
            if(empty($poll->title))
            {
                $poll->id    = 0;
                $poll->title = JText::_('COM_MIJOPOLLS_SELECT_POLL');
            }
            $options = $this->get('Options');
        }
        else
        {
            $options = array();
        }

        $pList = $this->get('Polls');

        foreach ($pList as $k => $p)
        {
            $pList[$k]->url = JRoute::_('index.php?option=com_mijopolls&view=poll&id=' . $p->slug);
        }

        array_unshift($pList, JHTML::_('select.option', '', JText::_('COM_MIJOPOLLS_SELECT_POLL'), 'url', 'title'));

        $lists          = array();
        $lists['polls'] = JHTML::_(
            'select.genericlist',
            $pList,
            'id',
            'class="inputbox" size="1" style="width:400px" onchange="if (this.options[selectedIndex].value != \'\') {document.location.href=this.options[selectedIndex].value}"',
            'url',
            'title',
            JRoute::_('index.php?option=com_mijopolls&view=poll&id=' . $poll->id . ':' . $poll->alias)
        );

        $voters         = isset($options[0]) ? $options[0]->voters : 0;
        $num_of_options = count($options);
        for ($i = 0; $i < $num_of_options; $i++)
        {
            $vote = $options[$i];
            if($voters > 0)
            {
                $vote->percent = round(100 * $vote->hits / $voters, 1);
            }
            else
            {
                if($params->get('show_what') == 1)
                {
                    $vote->percent = round(100 / $num_of_options, 1);
                }
                else
                {
                    $vote->percent = 0;
                }
            }
        }

        $title_lenght = $params->get('title_lenght');

        foreach ($options as $vote_array)
        {
            if($params->get('show_hits'))
            {
                $hits = " (" . $vote_array->hits . ")";
            }
            else
            {
                $hits = '';
            }

            if($params->get('show_zero_votes'))
            {
                $text     = JString::substr(html_entity_decode($vote_array->text, ENT_QUOTES, "utf-8"), 0, $title_lenght) . $hits;
                $values[] = '
				    "value":' . $vote_array->percent . ',
					"label":"' . addslashes($text) . '",
					"text":"' . addslashes($text) . '"
				';
            }
            else
            {
                if($vote_array->percent)
                {
                    $text     = JString::substr(html_entity_decode($vote_array->text, ENT_QUOTES, "utf-8"), 0, $title_lenght) . $hits;
                    $values[] = '
					    "value":' . $vote_array->percent . ',
						"label":"' . addslashes($text) . '",
						"text":"' . addslashes($text) . '"
					';
                }
            }
        }

        $cookieName      = JApplication::getHash($app->getName() . 'poll' . $poll_id);
        $cookieVoted     = JRequest::getVar($cookieName, '0', 'COOKIE', 'INT');
        $cookieVotedDone = @$_COOKIE['_donepoll' . $poll_id];

        $ipVoted = $model->ipVoted($poll, $poll_id);

        $now          = JHtml::date($now, 'Y-m-d H:i:s');
        $now          = strtotime($now);
        $publish_up   = strtotime($poll->publish_up);
        $publish_down = strtotime($poll->publish_down);

        $msgdone = 0;
        if($params->get('allow_voting'))
        {
            if(($now > $publish_up) && ($now < $publish_down))
            {
                if($params->get('only_registered'))
                {
                    if(!$user->guest)
                    {
                        if($params->get('one_vote_per_user'))
                        {
                            $query = $db->getQuery(true);
                            $query->select("date");
                            $query->from('#__mijopolls_votes');
                            $query->where('poll_id = ' . (int) $poll_id);
                            $query->where('user_id = ' . (int) $user->id);
                            $db->setQuery($query);
                            $userVoted = ($db->loadResult()) ? 1 : 0;

                            if($userVoted)
                            {
                                $allowToVote = 0;
                                $msg         = JText::_('COM_MIJOPOLLS_ALREADY_VOTED');
                            }
                            else
                            {
                                $allowToVote = 1;
                            }

                            if($cookieVotedDone)
                            {
                                $allowToVote = 0;
                                $msg         = JText::_('COM_MIJOPOLLS_THANK_YOU');
                                $msgdone     = 1;
                            }
                        }
                        else
                        {
                            if($cookieVoted)
                            {
                                $allowToVote = 0;
                                $msg         = JText::_('COM_MIJOPOLLS_ALREADY_VOTED');
                            }
                            else
                            {
                                $allowToVote = 1;
                            }

                            if($cookieVotedDone)
                            {
                                $allowToVote = 0;
                                $msg         = JText::_('COM_MIJOPOLLS_THANK_YOU');
                                $msgdone     = 1;
                            }
                        }
                    }
                    else
                    {
                        $allowToVote = 0;
                        $return      = JRequest::getURI();
                        $return      = base64_encode($return);
                        $link        = 'index.php?option=com_users&view=login&return=' . $return;
                        $msg         = JText::sprintf('COM_MIJOPOLLS_REGISTER_TO_VOTE', '<a href="' . $link . '">', '</a>');
                    }
                }
                else
                {
                    if($cookieVoted)
                    {
                        $allowToVote = 0;
                        $msg         = JText::_('COM_MIJOPOLLS_ALREADY_VOTED');
                    }
                    else
                    {
                        if($params->get('ip_check'))
                        {
                            if($ipVoted)
                            {
                                $allowToVote = 0;
                                $msg         = JText::_('COM_MIJOPOLLS_ALREADY_VOTED');
                            }
                            else
                            {
                                $allowToVote = 1;
                            }
                        }
                        else
                        {
                            $allowToVote = 1;
                        }
                    }

                    if($cookieVotedDone)
                    {
                        $allowToVote = 0;
                        $msg         = JText::_('COM_MIJOPOLLS_THANK_YOU');
                        $msgdone     = 1;
                    }
                }
            }
            else
            {
                $allowToVote = 0;
            }

            if($now < $publish_up) $msg = JText::_('COM_MIJOPOLLS_VOTE_NOT_STARTED');

            if($now > $publish_down) $msg = JText::_('COM_MIJOPOLLS_VOTE_ENDED');
        }
        else
        {
            $allowToVote = 0;
        }

        $this->lists       = $lists;
        $this->params      = $params;
        $this->poll        = $poll;
        $this->options     = $options;
        $this->allowToVote = $allowToVote;
        $this->msg         = $msg;
        $this->msgdone     = $msgdone;

        parent::display($tpl);
    }
}