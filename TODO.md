# Profile Update Fix - TODO
Status: In Progress

## Steps:
- [x] 1. Create this TODO.md
- [x] 2. Fix ProfileController.php: 
  - Remove duplicate Route annotation ✓
  - Add try-catch around persist/flush with error flash ✓
  - Skip empty string values (preserve null) ✓
  - Auto-create MentorProfile if missing for faculty/mentor ✓
  - Add debug logging temporarily (skipped)
- [x] 3. Update templates/profile/index.html.twig:
  - Add form validation (HTML5 + JS) ✓
  - Fix stuck saving button ✓
  - Improve degree display logic ✓
- [x] 4. Test form submission complete
- [ ] 5. Clear cache: bin/console cache:clear
- [ ] 6. Complete task

Next step: Edit ProfileController.php
