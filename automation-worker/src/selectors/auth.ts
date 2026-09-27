export const authSelectors = {
    soundfresh: {
        email: "Email Address *",
        password: "Password *",
        submit: "Login",
        authenticatedUrl: /\/admin(?:\/|$)/i,
    },
    soundon: {
        emailTab: "With Email",
        email: "Email Address",
        password: "Password",
        submit: "Log in",
        authenticatedUrl: /\/(?:dashboard|releases|catalog)(?:\/|\?|$)/i,
    },
    challenge:
        /captcha|verification code|one-time password|verify your device|verify to continue|security verification|slide to verify|otp/i,
    invalidCredentials:
        /invalid credentials|invalid email|incorrect password|email or password|credentials do not match|login failed|unable to log in/i,
};
