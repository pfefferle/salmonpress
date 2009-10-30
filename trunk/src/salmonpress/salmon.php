<?php
/**
 * Copyright 2009 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// Needed for email_exists
require_once dirname(__FILE__) . '/../../../wp-includes/registration.php';

require_once 'webfinger.php';

/**
 * Represents a single Salmon entry retrieved from a post to the Salmon
 * endpoint.
 */
class SalmonEntry {
  private $id;
  private $author_name;
  private $author_uri;
  private $thr_in_reply_to;
  private $content;
  private $title;
  private $updated;
  private $salmon_signature;
  private $webfinger;
  
  /**
   * Determines whether the current element being parsed has a parent with 
   * the given name.
   * @param array $atom An entry from xml_parse_into_struct.
   * @param string $parent The parent element's name we are checking for.
   * @param array $breadcrumbs An array of element names showing the current
   *     parse tree.
   * @return boolean True if the atom's parent's name is equal to the value
   *     of $parent.
   */
  private static function parent_is($atom, $parent, $breadcrumbs) {
    return ($breadcrumbs[$atom['level'] - 1] == $parent);     
  }
  
  /**
   * Converts an ATOM encoded Salmon post to a SalmonEntry.
   * @param string $atom_string The raw POST to the Salmon endpoint.
   * @return SalmonEntry An object representing the information in the POST.
   */
  public static function from_atom($atom_string) {
    $xml_parser = xml_parser_create(''); 
    $xml_values = array();
    $xml_tags = array();
    if(!$xml_parser) 
        return false; 
    xml_parser_set_option($xml_parser, XML_OPTION_TARGET_ENCODING, 'UTF-8'); 
    xml_parser_set_option($xml_parser, XML_OPTION_CASE_FOLDING, 0); 
    xml_parser_set_option($xml_parser, XML_OPTION_SKIP_WHITE, 1); 
    xml_parse_into_struct($xml_parser, trim($atom_string), $xml_values); 
    xml_parser_free($xml_parser); 
    
    $entry = new SalmonEntry();
    $breadcrumbs = array();
    for ($i = 0; $atom = $xml_values[$i]; $i++) {
      // Only process one entry.  This could be generalized to a feed later.
      if (strtolower($atom['tag']) == 'entry' && 
          strtolower($atom['type']) == 'close') {
        break;
      }
      // Keep a "breadcrumb" list of the tag hierarchy we're currently in.
      $breadcrumbs[$atom['level']] = $atom['tag'];
      
      // Parse individual attributes one at a time.
      switch (strtolower($atom['tag'])) {
        case 'id':
          $entry->id = $atom['value'];
          break;
        case 'name':
          if (SalmonEntry::parent_is($atom, 'author', $breadcrumbs)) {
            $entry->author_name = $atom['value'];
          }
          break;
        case 'uri':
          if (SalmonEntry::parent_is($atom, 'author', $breadcrumbs)) {
            $entry->author_uri = $atom['value'];
          }
          break; 
        case 'thr:in-reply-to':
          $entry->thr_in_reply_to = $atom['value'];
          break;
        case 'content':
          $entry->content = $atom['value'];
          break;
        case 'title':
          $entry->title = $atom['value'];
          break;
        case 'updated':
          $entry->updated = $atom['value'];
          break;
        case 'sal:signature':
          $entry->salmon_signature = $atom['value'];
          break;
      }
    }
    
    $entry->webfinger = WebFingerAccount::from_acct_string($entry->author_uri);
    return $entry;    
  }
  
  /**
   * Determines whether this SalmonEntry's signature is valid.
   * @return boolean True if the signature can be validated, False otherwise.
   */
  public function validate() {
    return false;
  }
  
  /**
   * Returns the data from this SalmonEntry in a $commentdata format, suitable
   * for passing to wp_new_comment.  
   * 
   * If the user's email address is a user of the current blog, this method
   * retrieves the user's data and merges it into the $commentdata structure.
   * 
   * @return array Data suitable for posting to wp_new_comment.
   */
  public function to_commentdata() {
    $time = strtotime($this->updated); 
    $matches = array();
    if (preg_match("/p=(?<pid>[0-9]+)/", $this->thr_in_reply_to, $matches)) {
      $pid = $matches['pid'];
    } else {
      return false;
    }
    
    $commentdata = array(
      'comment_post_ID' => $pid,
      'comment_author' => $this->author_name,
      'comment_author_url' => $this->author_uri,
      'comment_content' => $this->content,
      'comment_date' => $time,
      'comment_date_gmt' => $time
    );
    
    // Pulls user data
    // TODO(kurrik): This probably needs to be refactored out to SalmonPress.php
    if ($this->webfinger !== false) {
      $email = $this->webfinger->get_email();
      $uid = email_exists($email);
      if ($uid !== false) {
        $user_data = get_userdata($uid);
        $commentdata['comment_author'] = $user_data->display_name;
        $commentdata['comment_author_url'] = $user_data->user_url;
        $commentdata['comment_author_email'] = $email;
        $commentdata['user_id'] = $uid;
      }           
    }
    
    return $commentdata;
  }
}
