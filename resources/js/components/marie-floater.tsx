import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';

const TARGET_EMAILS = ['andrea.cardenas@grupocosteno.com'];
const STORAGE_KEY = 'marie-floater-dismissed';

export function MarieFloater() {
    const { auth } = usePage<SharedData>().props;
    const [dismissed, setDismissed] = useState(true);

    const isTarget = !!auth?.user?.email && TARGET_EMAILS.includes(auth.user.email);

    useEffect(() => {
        if (!isTarget) return;
        // sessionStorage is client-only, so we resolve the dismissed state on mount
        // (SSR renders nothing until then). This intentional post-mount setState is fine.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setDismissed(sessionStorage.getItem(STORAGE_KEY) === '1');
    }, [isTarget]);

    if (!isTarget || dismissed) {
        return null;
    }

    const handleClose = () => {
        sessionStorage.setItem(STORAGE_KEY, '1');
        setDismissed(true);
    };

    return (
        <div className="fixed bottom-4 right-4 z-50 animate-in fade-in slide-in-from-bottom-4 duration-300">
            <div className="relative flex flex-col items-center gap-2">
                <button
                    type="button"
                    onClick={handleClose}
                    aria-label="Cerrar"
                    className="absolute -right-1 -top-1 z-10 inline-flex h-6 w-6 items-center justify-center rounded-full border bg-background text-muted-foreground shadow-md transition-colors hover:bg-muted hover:text-foreground"
                >
                    <X className="h-3.5 w-3.5" />
                </button>

                <img
                    src="/images/marie.png"
                    alt="Marie"
                    className="h-28 w-auto drop-shadow-lg transition-transform hover:scale-105"
                    draggable={false}
                />
                {/* <img
                    src="/images/marie-play.png"
                    alt="Marie"
                    className="h-28 w-auto drop-shadow-lg transition-transform hover:scale-105"
                    draggable={false}
                /> */}

                <div className="relative animate-in fade-in zoom-in slide-in-from-top-1 duration-500 delay-300 fill-mode-both">
                    <div className="absolute -top-1 left-1/2 h-2 w-2 -translate-x-1/2 rotate-45 border-l border-t border-pink-200 bg-pink-50 dark:border-pink-900 dark:bg-pink-950/60" />
                    <div className="relative rounded-2xl border border-pink-200 bg-pink-50 px-3 py-1.5 shadow-lg dark:border-pink-900 dark:bg-pink-950/60">
                        <p className="font-display whitespace-nowrap text-sm text-pink-900 dark:text-pink-100">
                            Hola cosa, bonito día
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
