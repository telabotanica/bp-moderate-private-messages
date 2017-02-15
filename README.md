# bp-moderate-private-messages
A plugin for BuddyPress that allows to moderate private messages when the number of recipients exceeds the chosen limit

Set limit to 0 or '' to disable. Set limit to 1 to moderate every private message

This plugin was built along with [BP Members Directory Actions](https://github.com/telabotanica/bp-members-directory-actions), that allows sending messages to multiple recipients from members directory

## usage
Install, activate

## options
Go to WP admin panel > private messages > options

They are pretty self-explanatory

## how it works (regardless of options)
Adds a hook to `messages_message_before_save` and copies sent messages to a moderated messages table (see below). Sender receives a notification of queued message. Superadmins receive a notification inviting them to accept or reject the message. If accepted, the message is sent for real. The sender receives a notification whether the message was accepted or rejected.

## new tables
###`_bp_messages_moderated`
A copy of `bp_messages_messages` structure with additional fields :
 - `recipients` : comma separated list of moderated message's recipients
 - `deleted` : soft-delete system (0 => queued for moderation, 1 => deleted)
 
## new pages
### admin / private messages
#### admin / private messages / messages awaiting moderation
Searchable list of messages awaiting moderation. Includes accept / reject foncirmation forms and mechanisms
#### admin / private messages / options
Manage recipients limit before moderation, and notification options

## things to know about
The soft-delete system was supposed to be optional but as the notifications body is built on-demand, deleting the moderated messages history makes showing nice modifications pretty difficult
