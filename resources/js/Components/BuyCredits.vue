<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();
const open = ref(false);
const amounts = [5, 10, 25, 50];

const buy = (amount) => {
    router.post(route('credits.store'), { amount }, { preserveScroll: true, onSuccess: () => (open.value = false) });
};
</script>

<template>
    <div class="relative flex items-center gap-2 text-sm">
        <span class="text-gray-500">Balance</span>
        <span class="font-semibold text-gray-900" data-test="balance">{{ page.props.auth.balance }}</span>
        <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500" @click="open = !open">
            Buy credits
        </button>
        <div v-if="open" class="absolute right-0 top-9 z-20 w-60 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
            <p class="mb-2 text-xs text-gray-500">Simulated purchase: no real payment. Credits are US dollars.</p>
            <div class="grid grid-cols-2 gap-2">
                <button v-for="a in amounts" :key="a" type="button" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50" @click="buy(a)">
                    +${{ a }}
                </button>
            </div>
        </div>
    </div>
</template>
