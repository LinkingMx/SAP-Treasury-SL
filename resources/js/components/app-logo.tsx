export default function AppLogo() {
    return (
        <>
            {/* Light mode: navy logo (visible on light background) */}
            <img
                src="/images/logo_white.svg"
                alt="Costeño"
                className="h-8 w-auto dark:hidden"
            />
            {/* Dark mode: cream logo (visible on dark background) */}
            <img
                src="/images/logo_dark.svg"
                alt="Costeño"
                className="hidden h-8 w-auto dark:block"
            />
        </>
    );
}
