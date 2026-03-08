import { reactive } from 'vue';

export type ToastType = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    type: ToastType;
    message: string;
}

const state = reactive<{ toasts: Toast[] }>({ toasts: [] });
let nextId = 1;

export function useToast() {
    function add(message: string, type: ToastType = 'info', duration = 4000) {
        const id = nextId++;
        state.toasts.push({ id, type, message });
        setTimeout(() => remove(id), duration);
    }

    function remove(id: number) {
        const idx = state.toasts.findIndex(t => t.id === id);
        if (idx !== -1) state.toasts.splice(idx, 1);
    }

    return {
        toasts: state.toasts,
        success: (msg: string) => add(msg, 'success'),
        error: (msg: string) => add(msg, 'error'),
        info: (msg: string) => add(msg, 'info'),
        remove,
    };
}
