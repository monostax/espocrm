<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

/** What the Avisos number says, in the workspace's language (Brazilian Portuguese unless it is en_US). */
class Copy
{
    private const TEXT = [
        'pt_BR' => [
            'card.customer_waiting' => '🔥 *{name}* está esperando resposta há mais de *{wait}*.',
            'card.promise_due' => '⏰ *{name}* está esperando algo que você combinou.',
            'card.follow_up_due' => '⏳ *{name}* não responde há *{wait}*.',
            'card.next_step_missing' => '💤 A conversa com *{name}* está parada há *{wait}*.',
            'card.open' => '👉 Abrir card: {link}',
            'btn.suggestion' => 'Ver sugestão',
            'btn.resolved' => 'Já resolvi',
            'btn.later' => 'Mais tarde',
            'btn.send' => 'Enviar',
            'btn.edit' => 'Editar',
            'btn.next' => 'Próximo',
            'btn.pause' => 'Por hoje é só',
            'btn.cancel' => 'Cancelar',
            'btn.hour' => 'Em 1 hora',
            'btn.tomorrow' => 'Amanhã 8h',
            'btn.monday' => 'Segunda 8h',
            'btn.see' => 'Ver agora',
            'suggestion' => "💬 Sugestão para *{name}*:\n\n{draft}",
            'suggestion.long' => '💬 Sugestão para *{name}* (acima). Enviar?',
            'noDraft' => "Esse card não tem uma sugestão de resposta.\n👉 Abra a conversa: {link}",
            'open' => "👉 Card de *{name}*:\n{link}",
            'sent' => '✅ Enviado para *{name}* como {user}.',
            'resolved' => '✅ Marcado como resolvido.',
            'left.many' => 'Faltam {count} no seu Em dia.',
            'left.one' => 'Falta 1 no seu Em dia.',
            'later.ask' => '⏰ Quando te lembro de *{name}*?',
            'later.ok' => '⏰ Combinado, te lembro {when}.',
            'when.h' => 'em 1 hora',
            'when.t' => 'amanhã às 8h',
            'when.m' => 'segunda às 8h',
            'edit.ask' => '✏️ Escreva aqui a resposta para *{name}* e eu envio.',
            'edit.confirm' => "Enviar isto para *{name}*?\n\n{text}",
            'edit.cancelled' => 'Ok, não enviei nada.',
            'edit.tooLong' => 'Essa mensagem passou do limite de 4.000 caracteres. Escreva uma mais curta.',
            'changed' => '🔄 Essa conversa mudou desde o card. Não enviei nada.',
            'handled' => 'Esse card já foi tratado.',
            'noAccess' => '🔒 Você não tem mais acesso a essa conversa.',
            'frozen' => '⏸️ Seu espaço no Monostax está pausado. Os cards voltam quando ele for reativado.',
            'sendFailed' => '⚠️ Não consegui enviar a mensagem. Tente pelo Monostax: {link}',
            'done' => '🎉 Tudo em dia! Ninguém esperando resposta agora.',
            'pause' => '👍 Até mais! Escreva *em dia* aqui quando quiser ver os próximos.',
            'pause.morning' => '👍 Até mais! Seu card da manhã chega às {time}. Escreva *em dia* quando quiser ver os próximos.',
            'welcome' => "✅ Pronto, {name}! Seu Em dia de *{workspace}* agora chega aqui.\nEscreva *em dia* quando quiser ver seus cards, ou me pergunte qualquer coisa sobre seus clientes e negócios.",
            'welcome.morning' => "✅ Pronto, {name}! Seu Em dia de *{workspace}* agora chega aqui.\n☀️ Todo dia às {time} eu mando o primeiro card. Escreva *em dia* quando quiser ver mais, ou me pergunte qualquer coisa sobre seus clientes e negócios.",
            'stopped' => '🔕 Avisos desligados. Para voltar, ative de novo no Monostax em Configurações › Notificações no WhatsApp.',
            'code.unknown' => 'Não reconheci esse código. Gere um novo no Monostax e envie de novo.',
            'signup.ok' => '✅ WhatsApp confirmado! Pode voltar para a página do Monostax.',
            'signup.taken' => 'Este WhatsApp já tem um espaço no Monostax. Entre com seu e-mail em app.monostax.ai.',
            'choose' => 'Qual espaço você quer ver?',
            'choose.ai' => 'Em qual espaço eu procuro?',
            'ai.frozen' => '⏸️ Seu espaço no Monostax está pausado. A IA volta quando ele for reativado.',
            'ai.unavailable' => '🔒 Não há uma IA disponível para você neste espaço. Fale com o administrador do Monostax.',
            'ai.failed' => '⚠️ Não consegui terminar esse pedido. Tente de novo daqui a pouco.',
            'ai.blocked' => '⚠️ Os créditos de IA deste espaço acabaram. Peça a um administrador para recarregar no Monostax.',
            'ai.textOnly' => 'Por enquanto eu só entendo mensagens de texto. Escreva o que você precisa.',
            'ai.more' => '👉 A resposta continua no Monostax: {link}',
            'ai.empty' => 'Terminei, mas não tenho uma resposta em texto. Veja no Monostax: {link}',
            'choose.button' => 'Escolher espaço',
            'wait.minutes' => '{n} minutos',
            'wait.hour' => '1 hora',
            'wait.hours' => '{n} horas',
            'wait.day' => '1 dia',
            'wait.days' => '{n} dias',
            'wait.some' => 'algum tempo',
            'image.idle' => 'Parado há {wait}',
            'card.templateOpen' => "Abra o card no Em dia: {link}\n\nA sugestão de resposta já está pronta lá.",
            'suggestion.open' => '👉 Para enviar, abra o card: {link}',
            'later.open' => '⏰ Para adiar, abra o card: {link}',
            'edit.open' => '✏️ Para editar e enviar, abra o card: {link}',
            'then.more' => 'Escreva *em dia* para ver o próximo.',
            'digest.open' => '👉 Abrir o Em dia: {link}',
            'digest' => "☀️ Bom dia, {name}! Hoje no seu WhatsApp:\n🔴 Clientes esperando você: {waiting}\n💰 Negócios encontrados: {found}\n📅 Compromissos para hoje: {today}",
            'btn.stop' => 'Parar avisos',
        ],
        'en_US' => [
            'card.customer_waiting' => '🔥 *{name}* has been waiting for a reply for over *{wait}*.',
            'card.promise_due' => '⏰ *{name}* is waiting for something you promised.',
            'card.follow_up_due' => "⏳ *{name}* hasn't replied in *{wait}*.",
            'card.next_step_missing' => '💤 The conversation with *{name}* has been still for *{wait}*.',
            'card.open' => '👉 Open card: {link}',
            'btn.suggestion' => 'See suggestion',
            'btn.resolved' => 'Already done',
            'btn.later' => 'Later',
            'btn.send' => 'Send',
            'btn.edit' => 'Edit',
            'btn.next' => 'Next',
            'btn.pause' => 'Done for today',
            'btn.cancel' => 'Cancel',
            'btn.hour' => 'In 1 hour',
            'btn.tomorrow' => 'Tomorrow 8am',
            'btn.monday' => 'Monday 8am',
            'btn.see' => 'See now',
            'suggestion' => "💬 Suggested reply to *{name}*:\n\n{draft}",
            'suggestion.long' => '💬 Suggested reply to *{name}* (above). Send it?',
            'noDraft' => "This card has no suggested reply.\n👉 Open the conversation: {link}",
            'open' => "👉 *{name}*'s card:\n{link}",
            'sent' => '✅ Sent to *{name}* as {user}.',
            'resolved' => '✅ Marked as done.',
            'left.many' => '{count} left in your Em dia.',
            'left.one' => '1 left in your Em dia.',
            'later.ask' => '⏰ When should I remind you about *{name}*?',
            'later.ok' => "⏰ OK, I'll remind you {when}.",
            'when.h' => 'in 1 hour',
            'when.t' => 'tomorrow at 8am',
            'when.m' => 'on Monday at 8am',
            'edit.ask' => "✏️ Write your reply to *{name}* here and I'll send it.",
            'edit.confirm' => "Send this to *{name}*?\n\n{text}",
            'edit.cancelled' => "OK, I didn't send anything.",
            'edit.tooLong' => 'That message is over 4,000 characters. Write a shorter one.',
            'changed' => "🔄 This conversation changed since the card. I didn't send anything.",
            'handled' => 'This card was already handled.',
            'noAccess' => '🔒 You no longer have access to this conversation.',
            'frozen' => '⏸️ Your Monostax workspace is paused. Cards come back when it is reactivated.',
            'sendFailed' => "⚠️ I couldn't send the message. Try it in Monostax: {link}",
            'done' => "🎉 You're all caught up! Nobody is waiting for a reply.",
            'pause' => '👍 See you! Write *em dia* here whenever you want the next ones.',
            'pause.morning' => '👍 See you! Your morning card comes at {time}. Write *em dia* whenever you want the next ones.',
            'welcome' => "✅ Done, {name}! Your Em dia for *{workspace}* now comes here.\nWrite *em dia* whenever you want your cards, or ask me anything about your customers and deals.",
            'welcome.morning' => "✅ Done, {name}! Your Em dia for *{workspace}* now comes here.\n☀️ Every day at {time} I send the first card. Write *em dia* whenever you want more, or ask me anything about your customers and deals.",
            'stopped' => '🔕 Notifications off. To turn them back on, go to Settings › WhatsApp notifications in Monostax.',
            'code.unknown' => "I don't recognise that code. Create a new one in Monostax and send it again.",
            'signup.ok' => '✅ WhatsApp confirmed! You can go back to the Monostax page.',
            'signup.taken' => 'This WhatsApp already has a Monostax workspace. Log in with your email at app.monostax.ai.',
            'choose' => 'Which workspace do you want to see?',
            'choose.ai' => 'Which workspace should I look in?',
            'ai.frozen' => '⏸️ Your Monostax workspace is paused. The AI comes back when it is reactivated.',
            'ai.unavailable' => '🔒 No AI is available to you in this workspace. Ask your Monostax administrator.',
            'ai.failed' => "⚠️ I couldn't finish that request. Try again in a little while.",
            'ai.blocked' => '⚠️ This workspace is out of AI credits. Ask an administrator to top up in Monostax.',
            'ai.textOnly' => 'For now I only understand text messages. Write what you need.',
            'ai.more' => '👉 The answer continues in Monostax: {link}',
            'ai.empty' => "I'm done, but I have no text answer. See it in Monostax: {link}",
            'choose.button' => 'Choose workspace',
            'wait.minutes' => '{n} minutes',
            'wait.hour' => '1 hour',
            'wait.hours' => '{n} hours',
            'wait.day' => '1 day',
            'wait.days' => '{n} days',
            'wait.some' => 'a while',
            'image.idle' => 'Idle for {wait}',
            'card.templateOpen' => "Open the card in Em dia: {link}\n\nThe suggested reply is ready there.",
            'suggestion.open' => '👉 To send it, open the card: {link}',
            'later.open' => '⏰ To put it off, open the card: {link}',
            'edit.open' => '✏️ To edit and send it, open the card: {link}',
            'then.more' => 'Write *em dia* for the next one.',
            'digest.open' => '👉 Open Em dia: {link}',
            'digest' => "☀️ Good morning, {name}! Today in your WhatsApp:\n🔴 Customers waiting for you: {waiting}\n💰 Deals found: {found}\n📅 Due today: {today}",
            'btn.stop' => 'Stop notifications',
        ],
    ];

    /** @param array<string, string|int> $vars */
    public static function t(string $language, string $key, array $vars = []): string
    {
        $text = self::TEXT[self::language($language)][$key] ?? self::TEXT['pt_BR'][$key] ?? $key;
        foreach ($vars as $name => $value) $text = str_replace('{' . $name . '}', (string) $value, $text);
        return $text;
    }

    public static function language(?string $language): string
    {
        return $language === 'en_US' ? 'en_US' : 'pt_BR';
    }

    /** "6 dias", "3 horas", "40 minutos": how long, rounded down, never under one minute. */
    public static function wait(string $language, int $seconds): string
    {
        $minutes = max(1, intdiv($seconds, 60));
        if ($minutes < 60) return self::t($language, 'wait.minutes', ['n' => $minutes]);
        $hours = intdiv($minutes, 60);
        if ($hours < 24) return $hours === 1 ? self::t($language, 'wait.hour') : self::t($language, 'wait.hours', ['n' => $hours]);
        $days = intdiv($hours, 24);
        return $days === 1 ? self::t($language, 'wait.day') : self::t($language, 'wait.days', ['n' => $days]);
    }
}
