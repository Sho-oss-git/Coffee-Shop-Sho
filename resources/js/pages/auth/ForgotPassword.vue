<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Mail, KeyRound, ShieldCheck, Send } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Label } from '@/components/ui/label';
import IconInput from '@/components/auth/IconInput.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';

defineProps<{
  status?: string;
}>();

const form = useForm({
  email: '',
});

const submit = () => {
  form.post(route('password.email'));
};
</script>

<template>
  <Head title="Forgot password" />

  <div class="flex min-h-screen items-center justify-center bg-[#F7F3EE] p-4 dark:bg-[#101A16]">
    <div class="flex w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-[#16221D]">

      <!-- Left: form -->
      <div class="relative flex w-full flex-col justify-center px-6 py-10 sm:px-10 md:w-[55%] md:px-14">
        <div class="absolute right-6 top-6">
          <ThemeToggle />
        </div>

        <div class="mx-auto w-full max-w-sm">
          <div class="mb-8 flex flex-col items-start">
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[#1F3930]/10 dark:bg-white/10">
              <KeyRound class="h-6 w-6 text-[#1F3930] dark:text-white" />
            </div>
            <h2 class="text-3xl font-bold text-[#211812] dark:text-white">Forgot password?</h2>
            <p class="mt-1 text-sm text-[#211812]/60 dark:text-white/50">
              Enter your email to receive a password reset link.
            </p>
          </div>

          <div v-if="status" class="mb-4 rounded-lg bg-green-50 px-3 py-2 text-center text-sm font-medium text-green-700">
            {{ status }}
          </div>

          <form @submit.prevent="submit" class="flex flex-col gap-5">
            <div class="grid gap-2">
              <Label for="email" class="text-sm font-semibold text-[#211812] dark:text-white">Email Address</Label>
              <IconInput
                id="email"
                type="email"
                name="email"
                autocomplete="off"
                autofocus
                v-model="form.email"
                placeholder="Enter your email address"
              >
                <template #icon><Mail class="h-4 w-4" /></template>
              </IconInput>
              <InputError :message="form.errors.email" />
            </div>

            <button
              type="submit"
              :disabled="form.processing"
              class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#1F3930] text-sm font-semibold text-white shadow-md transition-colors hover:bg-[#284A3D] active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-70"
            >
              <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
              Email password reset link
            </button>
          </form>

          <div class="mt-6 space-x-1 text-center text-sm text-[#211812]/50 dark:text-white/40">
            <span>Or, return to</span>
            <TextLink :href="route('login')" class="font-medium text-[#1F3930] hover:underline dark:text-[#8FBFA8]">
              log in
            </TextLink>
          </div>

          <p class="mt-10 text-center text-xs text-[#211812]/40 dark:text-white/30">
            © 2026 JC66 Coffee Shop. All rights reserved.
          </p>
        </div>
      </div>

      <!-- Right: solid brand panel -->
      <div class="relative hidden w-[45%] flex-col justify-center overflow-hidden bg-[#1F3930] px-10 md:flex">
        <div class="absolute -left-6 top-1/2 h-12 w-12 -translate-y-1/2 rotate-45 bg-[#1F3930]" />

        <div class="relative z-10 mx-auto flex w-full max-w-xs flex-col items-center text-center">
          <div class="relative mb-8 flex h-40 w-40 items-center justify-center">
            <div class="absolute h-40 w-40 rounded-full bg-white/5" />
            <div class="absolute h-28 w-28 rounded-full bg-white/5" />

            <div class="absolute -left-2 top-2 flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-lg">
              <ShieldCheck class="h-4 w-4 text-[#1F3930]" />
            </div>
            <div class="absolute -right-2 top-8 flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-lg">
              <Send class="h-4 w-4 text-[#1F3930]" />
            </div>
            <div class="absolute -left-1 bottom-1 flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-lg">
              <KeyRound class="h-4 w-4 text-[#1F3930]" />
            </div>

            <!-- mock reset-link card -->
            <div class="flex h-24 w-32 flex-col justify-center gap-2 rounded-xl bg-white/95 p-3 shadow-xl">
              <div class="flex gap-1">
                <span class="h-1.5 w-1.5 rounded-full bg-[#E5B94B]" />
                <span class="h-1.5 w-1.5 rounded-full bg-[#6B4532]" />
                <span class="h-1.5 w-1.5 rounded-full bg-[#1F3930]" />
              </div>
              <span class="h-1.5 w-full rounded-full bg-[#1F3930]/70" />
              <span class="h-1.5 w-3/4 rounded-full bg-[#1F3930]/30" />
              <span class="h-1.5 w-5/6 rounded-full bg-[#1F3930]/30" />
            </div>
          </div>

          <h3 class="text-xl font-bold text-white">We'll get you back in.</h3>
          <p class="mt-2 text-sm text-white/70">
            Check your inbox for a secure link to reset your JC66 password.
          </p>

          <!-- <div class="mt-8 flex gap-2">
            <span class="h-1.5 w-1.5 rounded-full bg-white/30" />
            <span class="h-1.5 w-6 rounded-full bg-white" />
            <span class="h-1.5 w-1.5 rounded-full bg-white/30" />
          </div> -->
        </div>
      </div>
    </div>
  </div>
</template>