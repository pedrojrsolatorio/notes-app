# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Quick capture on the notes page: open it with N, the Quick capture button, or the floating button on small screens, then save with Ctrl or Cmd plus Enter. The first line becomes the title, the remaining lines become the note body, and the new note appears in All Notes (see spec 0002).
- An installable offline app at `/app` that keeps your notes and folders on the device, opens without a connection, and syncs changes when you reconnect. It adds offline folders, search, trash, quick capture, a sync status bar, and an Install app link on the existing notes page (see spec 0004).
- Conflict copies in All Notes when two devices edit the same note, attachment names with a message that a connection is needed, and a sign in prompt when the session expires. Local notes and queued changes stay on the device until you sign in again.

### Fixed
- Note list previews now show typed HTML tags as text instead of escaped entities.
- Restored the import and export settings page after it was removed.
