<?php
/**
 * Plugin Name: BuddyPress Notify Post Author on Blog Comment
 * Plugin URI: https://buddydev.com/plugins/bp-notify-post-author-on-blog-comment/
 * Author: BuddyDev
 * Author URI: https://buddydev.com/
 * Description: Notify the Blog post author of any new comment on their blog post
 * Version: 1.0.4
 * License: GPL
 * Text Domain: bp-notify-post-author-on-blog-comment
 * Domain Path: /languages/
 */

class DevB_Blog_Comment_Notifier {

	private static $instance;

	private $id = 'blog_comment_notifier';

	private function __construct() {

		add_action( 'bp_notification_settings', array( $this, 'screen_notification_settings' ) );
		add_action( 'bp_core_install_emails', array( $this, 'install_bp_emails' ) );

		add_action( 'bp_setup_globals', array( $this, 'setup_globals' ) );

		//On New comment
		add_action( 'comment_post', array( $this, 'comment_posted' ), 15, 2 );
		//on delete post, we should delete all notifications for the comment on that post
		//add_action( 'delete_post', array( $this, 'post_deleted' ), 10, 2 );

		// Monitor actions on existing comments
		add_action( 'deleted_comment', array( $this, 'comment_deleted' ) );
		//add_action( 'trashed_comment', array( $this, 'comment_deleted' ) );
		//add_action( 'spam_comment', array( $this, 'comment_deleted' ) );
		//should we do something on the action untrash_comment & unspam_comment

		add_action( 'wp_set_comment_status', array( $this, 'comment_status_changed' ) );

		// send email notifications
		add_action( 'bp_blog_comment_notifier_new_notification', array( $this, 'send_comment_notification' ), 10, 3 );

		// init
		add_action( 'bp_init', array( $this, 'init' ) );

		// Load plugin text domain
        add_action( 'bp_init', array( $this, 'load_textdomain' ) );
		add_action( 'template_redirect', array( $this, 'mark_read' ) );
	}

	/**
	 *
	 * @return DevB_Blog_Comment_Notifier
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

	/**
     * Call plugin activiation functions if plugin got activated
     *
     * @hook action plugins_loaded
     */
    public function init() {
		if ( get_option( '_bp_blog_comment_notifier_plugin_activated', false ) ) {
			delete_option( '_bp_blog_comment_notifier_plugin_activated' );
			$this->on_plugin_activation();
		}
	}

	/**
     * Plugin activiation hook
     *
     */
    public function on_plugin_activation() {
    	$this->install_bp_emails();
    }

    /**
     * Load plugin text domain
     *
     * @hook action plugins_loaded
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'bp-notify-post-author-on-blog-comment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

	public function setup_globals() {
		//BD: BuddyDev
		if ( ! defined( 'BD_BLOG_NOTIFIER_SLUG' ) ) {
			define( 'BD_BLOG_NOTIFIER_SLUG', 'bd-blog-notifier' );
		}

		$bp = buddypress();

		$bp->blog_comment_notifier = new stdClass();
		$bp->blog_comment_notifier->id = $this->id;//I asume others are not going to use this is
		$bp->blog_comment_notifier->slug = BD_BLOG_NOTIFIER_SLUG;
		$bp->blog_comment_notifier->notification_callback = array( $this, 'format_notifications' );//show the notification

		/* Register this in the active components array */
		$bp->active_components[ $bp->blog_comment_notifier->id ] = $bp->blog_comment_notifier->id;

		do_action( 'blog_comment_notifier_setup_globals' );
	}

	/** SETTINGS ************************************************************/

	/**
	 * Adds user configurable notification settings for the component.
	 *
	 * @global $bp The global BuddyPress settings variable created in bp_core_setup_globals()
	 */
	function screen_notification_settings() {
		if ( !$notify = bp_get_user_meta( bp_displayed_user_id(), 'notification_on_blog_comment', true ) )
			$notify = 'yes';
	?>

		<table class="notification-settings" id="follow-notification-settings">
			<thead>
				<tr>
					<th class="icon"></th>
					<th class="title"><?php _e( 'Comments', 'bp-notify-post-author-on-blog-comment' ) ?></th>
					<th class="yes"><?php _e( 'Yes', 'bp-notify-post-author-on-blog-comment' ) ?></th>
					<th class="no"><?php _e( 'No', 'bp-notify-post-author-on-blog-comment' )?></th>
				</tr>
			</thead>

			<tbody>
				<tr>
					<td></td>
					<td><?php _e( 'A member commented on your post or comment', 'bp-notify-post-author-on-blog-comment' ) ?></td>
					<td class="yes"><input type="radio" name="notifications[notification_on_blog_comment]" value="yes" <?php checked( $notify, 'yes', true ) ?>/></td>
					<td class="no"><input type="radio" name="notifications[notification_on_blog_comment]" value="no" <?php checked( $notify, 'no', true ) ?>/></td>
				</tr>
			</tbody>

			<?php do_action( 'bp_notify_post_author_on_blog_comment_screen_notification_settings' ); ?>
		</table>
	<?php
	}

	/**
	 * Get a list of emails
	 *t
	 * @since 1.0.0
	 *
	 * @return array
	 */
	function get_email_schema() {
		return array(
			'bp-blog-notifier-post-commented' => array(
				/* translators: do not remove {} brackets or translate its contents. */
				'post_title'   => __( '[{{{site.name}}}] {{poster.name}} commented on your {{post.post_type}}', 'bp-notify-post-author-on-blog-comment' ),
				/* translators: do not remove {} brackets or translate its contents. */
				'post_content' => __( "{{poster.name}} commented on your {{post.post_type}} with the title :\n\n<blockquote>&quot;{{post.title}}&quot;</blockquote>\n\nComment:\n\n<blockquote>&quot;{{comment.content}}&quot;</blockquote>\n\n<a href=\"{{{comment.url}}}\">View the comment</a>.", 'bp-notify-post-author-on-blog-comment' ),
				/* translators: do not remove {} brackets or translate its contents. */
				'post_excerpt' => __( "{{poster.name}} commented on your {{post.post_type}} with the title :\n\n\"{{post.title}}\"\n\nComment:\n\n\"{{comment.content}}\"\n\nView the comment: {{{comment.url}}}", 'bp-notify-post-author-on-blog-comment' ),
			),
			'bp-blog-notifier-comment-replied' => array(
				/* translators: do not remove {} brackets or translate its contents. */
				'post_title'   => __( '[{{{site.name}}}] {{poster.name}} replied to your comment', 'bp-notify-post-author-on-blog-comment' ),
				/* translators: do not remove {} brackets or translate its contents. */
				'post_content' => __( "{{poster.name}} replied to your comment on the {{post.post_type}} with the title :\n\n<blockquote>&quot;{{post.title}}&quot;</blockquote>\n\nComment:\n\n<blockquote>&quot;{{comment.content}}&quot;</blockquote>\n\n<a href=\"{{{comment.url}}}\">View the reply</a>.", 'bp-notify-post-author-on-blog-comment' ),
				/* translators: do not remove {} brackets or translate its contents. */
				'post_excerpt' => __( "{{poster.name}} replied to your comment on the {{post.post_type}} with the title :\n\n\"{{post.title}}\"\n\nComment:\n\n\"{{comment.content}}\"\n\nView the comment: {{{comment.url}}}", 'bp-notify-post-author-on-blog-comment' ),
			),
		);
	}

	/**
	 * Get a list of email type taxonomy terms.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	function get_email_type_schema() {
		return array(
			'bp-blog-notifier-post-commented'    => __( 'A Post the recipient created has been commented.', 'bp-notify-post-author-on-blog-comment' ),
			'bp-blog-notifier-comment-replied'    => __( 'A Comment by the recipient has been replied.', 'bp-notify-post-author-on-blog-comment' ),
		);
	}

	/**
	 * Installs BuddyPress Emails
	 *
	 * @package BuddyPress Followers Notifications
	 * @since 1.0.0
	 */
	function install_bp_emails() {

		$emails = $this->get_email_schema();
		$terms  = $this->get_email_type_schema();

		foreach ( $emails as $term_name => $email ) {

		    // Do not create if it already exists and is not in the trash
		    $post_exists = get_page_by_title( $email['post_title'], OBJECT, bp_get_email_post_type() );

		    if ( $post_exists && $post_exists->ID != 0 && get_post_status( $post_exists ) == 'publish' )
		       continue;

		    // Create post object
		    $email_post = array(
		      'post_title'    => $email['post_title'],
		      'post_content'  => $email['post_content'],  // HTML email content.
		      'post_excerpt'  => $email['post_excerpt'],  // Plain text email content.
		      'post_status'   => 'publish',
		      'post_type' 	  => bp_get_email_post_type() // this is the post type for emails
		    );

		    // Insert the email post into the database
		    $post_id = wp_insert_post( $email_post );

		    if ( $post_id ) {
		      // add our email to the right taxonomy term

		        $tt_ids = wp_set_object_terms( $post_id, $term_name, bp_get_email_tax_type() );
		        foreach ( $tt_ids as $tt_id ) {
		            $term = get_term_by( 'term_taxonomy_id', (int) $tt_id, bp_get_email_tax_type() );
		            wp_update_term( (int) $term->term_id, bp_get_email_tax_type(), array(
		                'description' => $terms[$term_name],
		            ) );
		        }
		    }
		}
	}

	/**
	 * Notify when  a comment is posted
	 *
	 * @param int $comment_id
	 * @param  $comment_status
	 * @return null
	 */
	public function comment_posted( $comment_id = 0, $comment_status = 0 ) {

		if ( ! $this->is_bp_active() ) {
			return ;
		}

		$comment = get_comment( $comment_id );

		//if the comment does not exists(not likely), or if the comment is marked as spam, we don't take any action
		if ( empty( $comment ) || $comment->comment_approved == 'spam' ) {
			return ;
		}

		//should we handle trackback? currently we don't
		if ( $comment->comment_type == 'trackback' || $comment->comment_type == 'pingback'  ) {
			return ;
		}


		$post_id = $comment->comment_post_ID;

		$post = get_post( $post_id );

		$this->notifiy_post_author( $post, $comment );

		$this->notifiy_comment_parent_author( $post, $comment );

	}

	public function notifiy_post_author( $post, $comment ) {

		//no need to generate any notification if an author is making comment
		if ( $post->post_author == $comment->user_id ) {
			return ;
		}

		//can the post author moderate comment?
		if ( ! user_can( $post->post_author, 'moderate_comments'  ) && $comment->comment_approved == 0 ) {
			return ;
		}
		//if we are here, we must provide a notification to the author of the post

		$this->notify( $post->post_author, $comment );

	}

	public function notifiy_comment_parent_author( $post, $comment ) {

		//if this is not a reply to another comment, do nothing
		if ( ! $comment->comment_parent ) {
			return ;
		}

		$parent_comment = get_comment( $comment->comment_parent );

		//if the comment does not exists(not likely), or if the comment is marked as spam, we don't take any action
		if ( empty( $parent_comment ) || $parent_comment->comment_approved == 'spam' ) {
			return ;
		}

		//if the parent comment does not come from a registered user, do nothing
		if ( ! $parent_comment->user_id ) {
			return ;
		}

		//no need to generate any notification if the author made the parent comment
		if ( $post->post_author == $parent_comment->user_id ) {
			return ;
		}

		//can the parent comments author moderate comment?
		if ( ! user_can( $parent_comment->user_id, 'moderate_comments'  ) && $comment->comment_approved == 0 ) {
			return;
		}

		//if we are here, we must provide a notification to the author of the parent comment

		$this->notify( $parent_comment->user_id, $comment );

	}

	/**
	 * When a comment status changes, we check for the notification and also
	 * think about changing the read link?
	 * @param int $comment_id
	 * @param int $comment_status
	 *
	 */
	public function comment_status_changed( $comment_id = 0, $comment_status = 0 ) {

		if ( ! $this->is_bp_active() ) {
			return ;
		}

		$comment = get_comment( $comment_id );

		if ( empty( $comment ) ) {
			return ;
		}

		//we are only interested in 2 cases
		//1. comment is notified and then it was marked as deleted or spam?
		if (  $comment->comment_approved == 'spam' || $comment->comment_approve == 'trash'  ) {

			if ( $this->is_notified( $comment_id ) ) {
				$this->comment_deleted( $comment_id );
			}

			return;
		}

		//if an approved comment is marked as pending,  delete notification
		if ( $comment->comment_approve == 0 && $this->is_notified( $comment_id ) ) {
			$this->comment_deleted( $comment_id );
			return ;

		}

		if ( $comment->comment_approve == 1 ) {

			$post = get_post( $comment->comment_post_ID );

			if ( get_current_user_id() == $post->post_author ) {

				if ( $this->is_notified( $comment_id ) ) {
					$this->comment_deleted ( $comment_id );
				}

				return ;

			} else {

				//if approver is not the author

				$this->notifiy_post_author( $post, $comment );

				$this->notifiy_comment_parent_author( $post, $comment );
			}

		}

	}
	/**
	 * On Comment Delete
	 * @param type $comment_id
	 */
	public function comment_deleted( $comment_id ) {


		if ( ! $this->is_bp_active() ) {
			return;
		}

		bp_notifications_delete_all_notifications_by_type( $comment_id, $this->id );

		$this->unmark_notified( $comment_id );
	}
	/**
	 * Generate human readable notification
	 *
	 * @param string $action
	 * @param string $comment_id
	 * @param string $secondary_item_id
	 * @param string $total_items
	 * @param string $format
	 * @return mixed
	 */
	public function format_notifications( $action, $comment_id, $secondary_item_id, $total_items, $format = 'string',  $notification_id = 0 ) {

		$bp = buddypress();
		$switched = false;
		$blog_id = bp_notifications_get_meta( $notification_id, '_blog_id' );

		if ( $blog_id && get_current_blog_id() != $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$comment = get_comment( $comment_id );

		$post = get_post( $comment->comment_post_ID);

		$link = $text = $name = $post_title = $comment_content ='';

		if ( $comment->user_id ) {
			$name = bp_core_get_user_displayname ( $comment->user_id );
		} else {
			$name = $comment->comment_author;
		}

		$post_title = $post->post_title;

		$comment_content = wp_trim_words( $comment->comment_content, 12,  ' ...' );

		if ( $comment->comment_parent &&
			( $parent_comment = get_comment( $comment->comment_parent ) ) &&
			get_current_user_id() == $parent_comment->user_id ) {

			$text = sprintf(
	            __( '%s replied to your comment on <strong>%s</strong>: <em>%s</em>', 'bp-notify-post-author-on-blog-comment' ),
	            $name,
	            $post_title,
	            $comment_content
	        );

		} else {

	        $text = sprintf(
	            __( '%s commented on <strong>%s</strong>: <em>%s</em>', 'bp-notify-post-author-on-blog-comment' ),
	            $name,
	            $post_title,
	            $comment_content
	        );
	    }

		if ( $comment->comment_approved == 1 ) {

				$link = get_comment_link ( $comment );

		} else {
			$link =admin_url( 'comment.php?action=approve&c=' . $comment_id );
		}

		if( $switched ) {
			restore_current_blog();
		}

		if ( $format == 'string' ) {

		 return apply_filters( 'bp_blog_notifier_new_comment_notification_string', '<a href="' . $link . '">' . $text . '</a>' );

		}else{

        return array(
                'link'  => $link,
                'text'  => $text);

		}

	return false;
	}

	/**
	 * Is BuddyPress Active
	 * We test to avoid any fatal errors when Bp is not active
	 *
	 * @return boolean
	 */
	public function is_bp_active() {

		if ( function_exists( 'buddypress' ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Was the comment already added to bp notification?
	 *
	 * @param type $comment_id
	 * @return boolean
	 */
	public function is_notified( $comment_id ) {

		return get_comment_meta( $comment_id, 'bd_post_author_notified', true );
	}
	/**
	 * Mark that a comment was notified to the post author
	 *
	 * @param int $comment_id
	 */
	public function mark_notified( $comment_id ) {

		update_comment_meta( $comment_id, 'bd_post_author_notified', 1 );
	}

	/**
	 * Delete the notification mark meta
	 *
	 * @param int $comment_id
	 */
	public function unmark_notified( $comment_id ) {

		delete_comment_meta( $comment_id, 'bd_post_author_notified' );
	}

	public function notify( $user_id, $comment ) {

		$comment_id = $comment->comment_ID;
		$notificatin_id = bp_notifications_add_notification( array(

                   'item_id'            => $comment_id,
                   'user_id'            => $user_id,
                   'component_name'     => $this->id,
                   'component_action'   => 'new_blog_comment_'. $comment_id,
                   'secondary_item_id'  => $comment->comment_post_ID,
                ));

		if ( $notificatin_id && is_multisite() ) {
			bp_notifications_add_meta( $notificatin_id, '_blog_id', get_current_blog_id() );
		}

		$this->mark_notified( $comment_id );

		do_action( 'bp_blog_comment_notifier_new_notification', $user_id, $comment_id, $comment );
	}

	/**
	 * Send email when a new post is commented
	 *
	 * @since 1.0.0
	 *
	 */
	function send_comment_notification( $user_id, $comment_ID, $comment = null ) {

		if ( ! $comment )
			$comment = get_comment( $comment_ID );

		$post = get_post( $comment->comment_post_ID );

		$post_type = get_post_type_object( $post->post_type );

		// If this user does not want email notifications, bail
		if ( 'no' == bp_get_user_meta( $user_id, 'notification_on_blog_comment', true ) )
			return;

		$is_reply = ( $comment->comment_parent &&
			( $parent_comment = get_comment( $comment->comment_parent ) ) &&
			$user_id == $parent_comment->user_id );

		$email_type   = $is_reply?'bp-blog-notifier-comment-replied':'bp-blog-notifier-post-commented';
		$poster_name  = $comment->user_id?bp_core_get_user_displayname( $comment->user_id ):$comment->comment_author;
		$comment_link = get_comment_link( $comment_ID );

		// Now email the user with the message
		$email_args = array(
			'tokens' => array(
				'poster.name'      => $poster_name,
				'post.title'       => $post->post_title,
				'post.post_type'   => $post_type->labels->singular_name,
				'comment.url'      => $comment_link,
				'comment.content'  => $comment->comment_content
			),
		);

		$ret = bp_send_email( $email_type, (int)$user_id, $email_args );

		if ( is_wp_error( $ret ) ) {
			error_log("Sending of Mail failed: " . $ret->get_error_message() );
		}

		/**
		 * Fires after the sending of a bp blog comment email notification.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Post $post        Post object.
		 */
		do_action( 'bp_blog_comment_notifier_sent_email', $post, $comment );
	}

	public function mark_read() {

		if ( ! is_singular() ) {
			return ;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return ;
		}

		return BP_Notifications_Notification::update(
			array( 'is_new' => 0 ),
			array( 'secondary_item_id' => $post_id,
			       'component_name'    => $this->id,
			       'user_id'           => get_current_user_id(),
				)
			);


	}

	//we need to delete all notification for the user when he/she visits the single blog post?
	//no, we won't as there is no sure way to know if the user has seen a comment on the front end or not


}
//initialize
DevB_Blog_Comment_Notifier::get_instance();

/**
 * Sets a flag if the plugin has just been activated
 *
 * @since 1.0.0
 */
function bp_blog_comment_notifier_activation_hook() {

	update_option( '_bp_blog_comment_notifier_plugin_activated', true );
}
register_activation_hook( __FILE__, 'bp_blog_comment_notifier_activation_hook' );