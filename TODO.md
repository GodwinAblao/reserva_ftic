# Updated TODO - Forgot Password Feature
**Verification Fix Complete** ✅

**New Task: Forgot Password Flow**
- [ ] Step 1: Add User fields - reset_token, reset_expires (migrate).
- [ ] Step 2: New controller methods - forgotPassword, verifyOtp, resetPassword.
- [ ] Step 3: Update login.html.twig - add Forgot Password button/form.
- [ ] Step 4: Create templates/security/forgot_password.html.twig, otp_verify.html.twig, reset_password.html.twig.
- [ ] Step 5: Create email/otp_reset.html.twig.
- [ ] Step 6: Add routes to config/routes/security.yaml.
- [ ] Step 7: Test full flow.
- [ ] Complete.

**Plan Summary:**
Login → Forgot Password → Enter email → Send OTP → Enter OTP → New/Confirm Password → Auto-login.

